<?php

use App\Models\ExpenseCategory;
use App\Models\LookupPaymentMethod;
use App\Models\Overhead;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overhead')] class extends Component {
    public ?Overhead $overhead = null;

    public ?int $category_id = null;
    public string $expense_date = '';
    public string $amount = '';
    public bool $has_vat = false;
    public string $payment_method = '';

    public function mount(): void
    {
        if ($this->overhead) {
            $this->category_id = $this->overhead->category_id;
            $this->expense_date = $this->overhead->expense_date->format('Y-m-d');
            $this->amount = (string) $this->overhead->amount;
            $this->has_vat = $this->overhead->has_vat;
            $this->payment_method = $this->overhead->payment_method;
        } else {
            $this->expense_date = now()->format('Y-m-d');
        }
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    #[Computed]
    public function paymentMethods()
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    public function save(): void
    {
        $this->validate([
            'category_id' => 'required|integer|exists:expense_categories,id',
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'has_vat' => 'boolean',
            'payment_method' => 'required|string|max:50',
        ]);

        $data = [
            'category_id' => $this->category_id,
            'expense_date' => $this->expense_date,
            'amount' => $this->amount,
            'has_vat' => $this->has_vat,
            'payment_method' => $this->payment_method,
        ];

        if ($this->overhead === null) {
            $overhead = Overhead::create($data);
            Flux::toast(variant: 'success', text: 'Overhead recorded.');
            $this->redirect(route('overheads.show', $overhead), navigate: true);

            return;
        }

        $this->overhead->update($data);
        Flux::toast(variant: 'success', text: 'Overhead updated.');
        $this->redirect(route('overheads.show', $this->overhead), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="{{ $overhead ? 'Edit: #'.$overhead->id : 'Record Overhead' }}"
        subtitle="{{ $overhead ? 'Update overhead details.' : 'Log a business expense.' }}"
    >
        <x-slot:action>
            <flux:button
                variant="ghost"
                icon="arrow-left"
                :href="$overhead ? route('overheads.show', $overhead) : route('overheads.index')"
                wire:navigate
            >
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

        <div
            class="flex min-w-0 flex-1 flex-col gap-4"
            x-data="formNav"
            x-on:keydown="handleKey($event)"
            x-on:exit-confirm-discard.window="window.location.href = '{{ $overhead ? route('overheads.show', $overhead) : route('overheads.index') }}'"
            x-on:exit-confirm-save.window="$wire.save()"
        >
            <form wire:submit="save" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900 max-w-4xl">

                <div class="px-4 py-4">
                    <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Overhead Details</h2>
                    <div class="grid gap-4 md:grid-cols-2">

                        {{-- Category --}}
                        <div>
                            <flux:label>{{ __('Category') }} <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model="category_id" class="mt-1.5">
                                <flux:select.option value="">— Select category —</flux:select.option>
                                @foreach($this->categories as $category)
                                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('category_id') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        {{-- Expense Date --}}
                        <div>
                            <flux:input
                                wire:model="expense_date"
                                type="date"
                                :label="__('Expense Date')"
                                required
                            />
                            @error('expense_date') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        {{-- Amount --}}
                        <div>
                            <flux:input
                                wire:model.blur="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                prefix="£"
                                :label="__('Amount')"
                                required
                            />
                            @error('amount') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        {{-- Payment Method --}}
                        <div>
                            <flux:label>{{ __('Payment Method') }} <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model="payment_method" class="mt-1.5">
                                <flux:select.option value="">— Select method —</flux:select.option>
                                @foreach($this->paymentMethods as $method)
                                    <flux:select.option value="{{ $method->name }}">{{ $method->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('payment_method') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                    </div>

                    {{-- VAT checkbox --}}
                    <div class="mt-4 flex items-center gap-3">
                        <flux:checkbox wire:model="has_vat" id="has_vat" />
                        <flux:label for="has_vat">{{ __('Includes VAT') }}</flux:label>
                    </div>
                </div>

                {{-- Sticky footer actions --}}
                <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
                    <flux:button
                        variant="ghost"
                        :href="$overhead ? route('overheads.show', $overhead) : route('overheads.index')"
                        wire:navigate
                        data-form-nav
                    >
                        Cancel
                    </flux:button>
                    <flux:button variant="primary" type="submit" data-form-nav data-form-submit>
                        {{ $overhead ? 'Save Changes' : 'Save Overhead' }}
                    </flux:button>
                </div>

            </form>
        </div>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
