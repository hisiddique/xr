<?php

use App\Models\User;
use App\UserRole;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public User $user;

    public function deleteUser(): void
    {
        if ($this->user->id === auth()->id()) {
            Flux::toast(variant: 'danger', text: __('You cannot delete your own account.'));

            return;
        }

        if ($this->user->role === UserRole::Admin && User::where('role', 'admin')->count() <= 1) {
            Flux::toast(variant: 'danger', text: __('At least one admin must remain.'));

            return;
        }

        $name = $this->user->name;
        $this->user->delete();

        Flux::toast(variant: 'success', text: __('User :name deleted.', ['name' => $name]));

        $this->dispatch('user-deleted');
        Flux::modal('delete-user-'.$this->user->id)->close();
    }
}; ?>

<div>
    <flux:button
        size="xs"
        variant="ghost"
        icon="trash"
        x-on:click="$flux.modal('delete-user-{{ $user->id }}').show()"
        class="text-red-500 hover:text-red-700"
    >
        {{ __('Delete') }}
    </flux:button>

    <flux:modal name="delete-user-{{ $user->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete User') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete :name? This action cannot be undone.', ['name' => $user->name]) }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="deleteUser"
                    wire:loading.attr="disabled"
                >
                    {{ __('Delete User') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
