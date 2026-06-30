<?php

use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\LookupTitle;
use App\Models\Supplier;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier')] class extends Component {
    public ?Supplier $supplier = null;

    public string $company_name = '';
    public string $reference = '';
    public ?int $title_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $trade_discount = '0';
    public bool $vat_applied = false;
    public ?int $credit_limit_id = null;
    public ?int $credit_term_id = null;
    public string $supplier_vat_number = '';
    public string $email = '';
    public bool $vat_registered = false;
    public string $address_line_1 = '';
    public string $address_line_2 = '';
    public string $town_city = '';
    public string $post_code = '';

    public function mount(): void
    {
        if ($this->supplier) {
            $this->company_name = $this->supplier->company_name;
            $this->reference = $this->supplier->reference ?? '';
            $this->title_id = $this->supplier->title_id;
            $this->first_name = $this->supplier->first_name ?? '';
            $this->last_name = $this->supplier->last_name ?? '';
            $this->trade_discount = (string) $this->supplier->trade_discount;
            $this->vat_applied = (bool) $this->supplier->vat_applied;
            $this->credit_limit_id = $this->supplier->credit_limit_id;
            $this->credit_term_id = $this->supplier->credit_term_id;
            $this->supplier_vat_number = $this->supplier->supplier_vat_number ?? '';
            $this->email = $this->supplier->email ?? '';
            $this->vat_registered = (bool) $this->supplier->vat_registered;
            $this->address_line_1 = $this->supplier->address_line_1 ?? '';
            $this->address_line_2 = $this->supplier->address_line_2 ?? '';
            $this->town_city = $this->supplier->town_city ?? '';
            $this->post_code = $this->supplier->post_code ?? '';
        }
    }

    public function save(): void
    {
        $referenceRule = $this->supplier
            ? 'nullable|string|max:50|unique:suppliers,reference,'.$this->supplier->id
            : 'nullable|string|max:50|unique:suppliers,reference';

        $validated = $this->validate([
            'company_name' => 'required|string|max:255',
            'reference' => $referenceRule,
            'title_id' => 'nullable|integer|exists:lookup_titles,id',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'trade_discount' => 'numeric|min:0|max:100',
            'vat_applied' => 'boolean',
            'credit_limit_id' => 'nullable|integer|exists:lookup_credit_limits,id',
            'credit_term_id' => 'nullable|integer|exists:lookup_credit_terms,id',
            'supplier_vat_number' => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:255',
            'vat_registered' => 'boolean',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'town_city'      => 'nullable|string|max:100',
            'post_code'      => 'nullable|string|max:20',
        ]);

        if ($this->supplier === null) {
            $supplier = Supplier::create(array_merge($validated, [
                'created_by' => auth()->id(),
            ]));

            Flux::toast(variant: 'success', text: 'Supplier created.');
            $this->redirect(route('suppliers.show', $supplier), navigate: true);

            return;
        }

        $this->supplier->update($validated);

        Flux::toast(variant: 'success', text: 'Supplier updated.');
        $this->redirect(route('suppliers.show', $this->supplier), navigate: true);
    }

    #[Computed]
    public function titles()
    {
        return LookupTitle::orderBy('name')->get();
    }

    #[Computed]
    public function creditTerms()
    {
        return LookupCreditTerm::orderBy('name')->get();
    }

    #[Computed]
    public function creditLimits()
    {
        return LookupCreditLimit::orderBy('amount')->get();
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="{{ $supplier ? 'Edit: '.$supplier->company_name : 'New Supplier' }}"
        subtitle="{{ $supplier ? 'Update supplier details and trading terms.' : 'Add a new supplier to your CRM.' }}"
    >
        <x-slot:action>
            <flux:button
                variant="ghost"
                icon="arrow-left"
                :href="$supplier ? route('suppliers.show', $supplier) : route('suppliers.index')"
                wire:navigate
            >
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

        <form
            wire:submit="save"
            x-data="formNav"
            x-on:keydown="handleKey($event)"
            class="flex min-w-0 flex-1 flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900"
        >

            {{-- Section: Basic Information --}}
            <div class="px-4 py-4">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Basic Information</h2>
                <div class="grid gap-4 md:grid-cols-2" x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                    <flux:input wire:model="company_name" :label="__('Company Name')" required />
                    <flux:input wire:model="reference" :label="__('Reference')" :placeholder="__('e.g. SUP-0001')" />
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="email" type="email" :label="__('Email Address')" />
                    <flux:radio.group wire:model="vat_registered" :label="__('VAT Registered')" class="flex flex-row gap-6">
                        <flux:radio :value="1" :label="__('Yes')" />
                        <flux:radio :value="0" :label="__('No')" />
                    </flux:radio.group>
                </div>
            </div>

            {{-- Section: Primary Address --}}
            <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Primary Address</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="address_line_1" :label="__('Address Line 1')" />
                    <flux:input wire:model="address_line_2" :label="__('Address Line 2')" />
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="town_city" :label="__('Town / City')" />
                    <flux:input wire:model="post_code" :label="__('Post Code')" />
                </div>
            </div>

            {{-- Section: Contact Person (hidden) --}}
            <div class="hidden border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Contact Person</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    <flux:select wire:model="title_id" :label="__('Title')">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach($this->titles as $title)
                            <flux:select.option :value="$title->id">{{ $title->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="first_name" :label="__('First Name')" />
                    <flux:input wire:model="last_name" :label="__('Last Name')" />
                </div>
            </div>

            {{-- Section: Credit, Discount & Tax --}}
            <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Credit, Discount & Tax</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input
                        wire:model="trade_discount"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        :label="__('Trade Discount (%)')"
                    />
                    <div class="flex items-end pb-1">
                        <div class="flex items-center gap-3">
                            <flux:checkbox wire:model="vat_applied" id="vat_applied_form" />
                            <flux:label for="vat_applied_form">{{ __('VAT Applied') }}</flux:label>
                        </div>
                    </div>
                </div>

                <div class="mt-4 hidden grid gap-4 md:grid-cols-2">
                    <flux:select wire:model="credit_limit_id" :label="__('Credit Limit')">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach($this->creditLimits as $limit)
                            <flux:select.option :value="$limit->id">£{{ number_format($limit->amount, 2) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="credit_term_id" :label="__('Credit Terms')">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach($this->creditTerms as $term)
                            <flux:select.option :value="$term->id">{{ $term->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="mt-4 hidden">
                    <flux:input
                        wire:model="supplier_vat_number"
                        :label="__('Supplier VAT Number')"
                        :placeholder="__('e.g. GB123456789')"
                    />
                </div>
            </div>

            {{-- Sticky footer actions --}}
            <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
                <x-ui.back-button :fallback="$supplier ? route('suppliers.show', $supplier) : route('suppliers.index')" />
                <flux:button variant="primary" type="submit">{{ $supplier ? 'Save Changes' : 'Create Supplier' }}</flux:button>
            </div>

        </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
