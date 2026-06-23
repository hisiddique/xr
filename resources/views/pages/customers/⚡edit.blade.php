<?php

use App\Models\Customer;
use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\LookupTitle;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Edit Customer')] class extends Component {
    public Customer $customer;

    #[Validate('required|string|max:255')]
    public string $company_name = '';

    #[Validate('nullable|string|max:50')]
    public string $reference = '';

    #[Validate('nullable|integer|exists:lookup_titles,id')]
    public ?int $title_id = null;

    #[Validate('nullable|string|max:100')]
    public string $first_name = '';

    #[Validate('nullable|string|max:100')]
    public string $last_name = '';

    #[Validate('nullable|string|max:255')]
    public string $address_1 = '';

    #[Validate('nullable|string|max:255')]
    public string $address_2 = '';

    #[Validate('nullable|string|max:100')]
    public string $town = '';

    #[Validate('nullable|string|max:20')]
    public string $post_code = '';

    #[Validate('nullable|email|max:255')]
    public string $email_1 = '';

    #[Validate('numeric|min:0|max:100')]
    public string $trade_discount = '0';

    #[Validate('nullable|integer|exists:lookup_credit_terms,id')]
    public ?int $credit_term_id = null;

    #[Validate('nullable|integer|exists:lookup_credit_limits,id')]
    public ?int $credit_limit_id = null;

    #[Validate('boolean')]
    public bool $vat_registered = false;

    public function mount(): void
    {
        $this->company_name = $this->customer->company_name;
        $this->reference = $this->customer->reference ?? '';
        $this->title_id = $this->customer->title_id;
        $this->first_name = $this->customer->first_name ?? '';
        $this->last_name = $this->customer->last_name ?? '';
        $this->address_1 = $this->customer->address_1 ?? '';
        $this->address_2 = $this->customer->address_2 ?? '';
        $this->town = $this->customer->town ?? '';
        $this->post_code = $this->customer->post_code ?? '';
        $this->email_1 = $this->customer->email_1 ?? '';
        $this->trade_discount = (string) $this->customer->trade_discount;
        $this->credit_term_id = $this->customer->credit_term_id;
        $this->credit_limit_id = $this->customer->credit_limit_id;
        $this->vat_registered = (bool) $this->customer->vat_registered;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'company_name' => 'required|string|max:255',
            'reference' => 'nullable|string|max:50|unique:customers,reference,'.$this->customer->id,
            'title_id' => 'nullable|integer|exists:lookup_titles,id',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'address_1' => 'nullable|string|max:255',
            'address_2' => 'nullable|string|max:255',
            'town' => 'nullable|string|max:100',
            'post_code' => 'nullable|string|max:20',
            'email_1' => 'nullable|email|max:255',
            'trade_discount' => 'numeric|min:0|max:100',
            'credit_term_id' => 'nullable|integer|exists:lookup_credit_terms,id',
            'credit_limit_id' => 'nullable|integer|exists:lookup_credit_limits,id',
            'vat_registered' => 'boolean',
        ]);

        $this->customer->update($validated);

        Flux::toast(variant: 'success', text: __('Customer updated successfully.'));
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
        :title="'Edit: '.$customer->company_name"
        subtitle="Update this customer's details and trading terms."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('customers.show', $customer)" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <form wire:submit="save" x-data="formNav" x-on:keydown="handleKey($event)" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900 max-w-4xl">

        {{-- Section: Company --}}
        <div class="px-4 py-4">
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Basic Information</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="company_name" :label="__('Company Name')" required autofocus />
                <flux:input wire:model="reference" :label="__('Reference')" :placeholder="__('e.g. CUST-001')" />
            </div>
        </div>

        {{-- Section: Contact --}}
        <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
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
            <div class="mt-4">
                <flux:input wire:model="email_1" type="email" :label="__('Email Address')" />
            </div>
        </div>

        {{-- Section: Address --}}
        <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Delivery Address</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="address_1" :label="__('Address Line 1')" />
                <flux:input wire:model="address_2" :label="__('Address Line 2')" />
                <flux:input wire:model="town" :label="__('Town / City')" />
                <flux:input wire:model="post_code" :label="__('Post Code')" />
            </div>
        </div>

        {{-- Section: Credit & Trading --}}
        <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Credit & Discount</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input
                    wire:model="trade_discount"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    :label="__('Trade Discount (%)')"
                />
                <flux:select wire:model="credit_term_id" :label="__('Credit Terms')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach($this->creditTerms as $term)
                        <flux:select.option :value="$term->id">{{ $term->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="credit_limit_id" :label="__('Credit Limit')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach($this->creditLimits as $limit)
                        <flux:select.option :value="$limit->id">£{{ number_format($limit->amount, 2) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <flux:checkbox wire:model="vat_registered" id="vat_registered_edit" />
                <flux:label for="vat_registered_edit">{{ __('VAT Registered') }}</flux:label>
            </div>
        </div>

        {{-- Sticky footer actions --}}
        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <x-ui.back-button :fallback="route('customers.show', $customer)" />
            <flux:button variant="primary" type="submit">Save Changes</flux:button>
        </div>
    </form>

</div>