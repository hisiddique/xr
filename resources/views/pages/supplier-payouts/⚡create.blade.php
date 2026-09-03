<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record Supplier Payout')] class extends Component
{
    //
}; ?>

<div class="flex flex-col gap-6">

    <x-ui.page-header
        title="Record Supplier Payout"
        subtitle="Record an outbound payment to a supplier and allocate it against outstanding invoices."
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('supplier-payouts.index')" wire:navigate>
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <livewire:pages::supplier-payouts.create-panel :key="'payout-panel'" />

    <livewire:pages::supplier-payouts.report-summary :key="'report-summary'" />

</div>
