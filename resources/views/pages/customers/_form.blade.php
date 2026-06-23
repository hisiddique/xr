{{-- Shared customer form partial --}}
<div class="grid gap-6">
    <div class="grid gap-4 md:grid-cols-2">
        <flux:input
            wire:model="company_name"
            :label="__('Company Name')"
            required
            autofocus
        />

        <flux:input
            wire:model="reference"
            :label="__('Reference')"
            :placeholder="__('e.g. CUST-001')"
        />
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <flux:select wire:model="title_id" :label="__('Title')">
            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
            @foreach($titles as $title)
                <flux:select.option :value="$title->id">{{ $title->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="first_name" :label="__('First Name')" />
        <flux:input wire:model="last_name" :label="__('Last Name')" />
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:input wire:model="address_1" :label="__('Address Line 1')" />
        <flux:input wire:model="address_2" :label="__('Address Line 2')" />
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:input wire:model="town" :label="__('Town / City')" />
        <flux:input wire:model="post_code" :label="__('Post Code')" />
    </div>

    <flux:input wire:model="email_1" type="email" :label="__('Email Address')" />

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
            @foreach($creditTerms as $term)
                <flux:select.option :value="$term->id">{{ $term->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="credit_limit_id" :label="__('Credit Limit')">
            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
            @foreach($creditLimits as $limit)
                <flux:select.option :value="$limit->id">£{{ number_format($limit->amount, 2) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex items-center gap-3">
        <flux:checkbox wire:model="vat_registered" id="vat_registered_form" />
        <flux:label for="vat_registered_form">{{ __('VAT Registered') }}</flux:label>
    </div>
</div>
