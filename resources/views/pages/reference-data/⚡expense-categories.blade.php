<?php

use App\Models\ExpenseCategory;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Expense Categories')] class extends Component {
    public string $newCategory = '';

    public ?int $deletingCategoryId = null;

    public function addCategory(): void
    {
        $this->validateOnly('newCategory', ['newCategory' => 'required|string|max:100|unique:expense_categories,name']);
        ExpenseCategory::create(['name' => trim($this->newCategory)]);
        $this->newCategory = '';
        Flux::toast(variant: 'success', text: __('Category added.'));
    }

    public function deleteCategory(): void
    {
        if (! $this->deletingCategoryId) {
            return;
        }

        ExpenseCategory::findOrFail($this->deletingCategoryId)->delete();
        $this->deletingCategoryId = null;
        Flux::modal('delete-category')->close();
        Flux::toast(variant: 'success', text: __('Category deleted.'));
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    #[Computed]
    public function deletingCategory(): ?ExpenseCategory
    {
        return $this->deletingCategoryId
            ? ExpenseCategory::find($this->deletingCategoryId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Expense Categories"
        subtitle="Petrol, Food, Office Supplies, etc."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Expense Categories</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Categories used for tracking business overheads</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addCategory" class="flex gap-2">
                    <flux:input wire:model="newCategory" :placeholder="__('e.g. Petrol')" maxlength="100" class="flex-1" data-add-input />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newCategory" />
            </div>
            @if($this->categories->isEmpty())
                <x-ui.empty-state icon="tag" title="No categories yet" description="Add your first expense category above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->categories as $category)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $category->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingCategoryId', {{ $category->id }})"
                                        x-on:click="$flux.modal('delete-category').show()"
                                        class="text-rose-500 hover:text-rose-600"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Modals --}}
    <flux:modal name="delete-category" focusable class="max-w-sm" @close="$wire.set('deletingCategoryId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingCategory)
                    {{ __('Delete ":name"?', ['name' => $this->deletingCategory->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteCategory">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
