<?php

use App\Models\Customer;
use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\LookupTitle;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('New Customer')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $company_name = '';

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

    public function save(): void
    {
        $validated = $this->validate();

        Customer::create(array_merge($validated, [
            'reference' => $this->nextReference(),
            'created_by' => Auth::id(),
        ]));

        Flux::toast(variant: 'success', text: __('Customer created successfully.'));

        $this->redirect(route('customers.index'), navigate: true);
    }

    private function nextReference(): string
    {
        $last = Customer::where('reference', 'like', 'CUST-%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, 5)) + 1 : 1;

        return 'CUST-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
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
        title="New Customer"
        subtitle="Add a new company or contact to your CRM."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('customers.index')" wire:navigate>
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
                <flux:input wire:model="email_1" type="email" :label="__('Email Address')" />
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
        </div>

        {{-- Sticky footer actions --}}
        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <x-ui.back-button :fallback="route('customers.index')" />
            <flux:button variant="primary" type="submit">Create Customer</flux:button>
        </div>
    </form>

</div>
