<?php

use App\Models\Supplier;
use App\Services\SupplierStatementService;
use App\Support\StatementPeriod;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Supplier $supplier;

    public string $preset = 'this_month';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $outstandingOnly = true;
    public string $minBalance = '';
    public string $action = 'print';

    public bool $includeInvoices = true;
    public bool $includeDebitNotes = false;
    public bool $includePayouts = false;

    /** @var array<int, string> */
    public array $statementEmails = [];

    public string $statementNotes = '';

    public function mount(): void
    {
        $this->applyPreset();
    }

    public function updatedPreset(): void
    {
        $this->applyPreset();
    }

    protected function applyPreset(): void
    {
        if ($this->preset === 'custom') {
            return;
        }

        $period = StatementPeriod::fromPreset($this->preset);
        $this->dateFrom = $period->from ?? '';
        $this->dateTo = $period->to ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(): array
    {
        return [
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'outstandingOnly' => $this->outstandingOnly,
            'minBalance' => $this->minBalance,
            'includeInvoices' => $this->includeInvoices,
            'includeDebitNotes' => $this->includeDebitNotes,
            'includePayouts' => $this->includePayouts,
        ];
    }

    public function generate(): void
    {
        if (! $this->includeInvoices && ! $this->includeDebitNotes && ! $this->includePayouts) {
            $this->addError('includeInvoices', __('Select at least one document type to include.'));

            return;
        }

        if ($this->action === 'email') {
            $this->validate([
                'statementEmails' => 'required|array|min:1',
                'statementEmails.*' => 'email|max:254',
                'statementNotes' => 'nullable|string|max:2000',
            ]);

            try {
                app(SupplierStatementService::class)->sendStatementEmail(
                    $this->supplier,
                    $this->filters(),
                    $this->statementEmails,
                    $this->statementNotes ?: null,
                );

                Flux::modal('supplier-statement-'.$this->supplier->id)->close();
                Flux::toast(variant: 'success', text: __('Statement sent to :recipient.', ['recipient' => $this->statementEmails[0]]));
            } catch (\Throwable $e) {
                $this->addError('statementEmails', $e->getMessage());
            }

            return;
        }

        $url = route('suppliers.statement.export', array_merge(
            ['supplier' => $this->supplier, 'inline' => $this->action === 'print' ? 1 : 0],
            $this->filters(),
        ));

        Flux::modal('supplier-statement-'.$this->supplier->id)->close();
        $this->dispatch('statement-ready', url: $url, action: $this->action);
    }
}; ?>

<div x-data x-on:statement-ready.window="$event.detail.action === 'print' ? window.printPdfDocument($event.detail.url) : (window.location.href = $event.detail.url)">
    <flux:button
        variant="ghost"
        icon="document-text"
        size="sm"
        x-on:click="$flux.modal('supplier-statement-{{ $supplier->id }}').show()"
    >
        {{ __('Generate Statement') }}
    </flux:button>

    <flux:modal name="supplier-statement-{{ $supplier->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Generate Supplier Statement') }}</flux:heading>
                <flux:subheading>
                    {{ __('Statement of account for :company.', ['company' => $supplier->company_name]) }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <div>
                    <flux:label>{{ __('Period') }}</flux:label>
                    <flux:select wire:model.live="preset" class="mt-1.5">
                        @foreach(StatementPeriod::presets() as $value => $label)
                            <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if($preset === 'custom')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input wire:model.live="dateFrom" type="date" :label="__('From')" />
                        <flux:input wire:model.live="dateTo" type="date" :label="__('To')" />
                    </div>
                @endif

                <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-white/10">
                    <flux:label>{{ __('Outstanding invoices only') }}</flux:label>
                    <flux:switch wire:model.live="outstandingOnly" />
                </div>

                <div>
                    <flux:label>{{ __('Include') }}</flux:label>
                    <div class="mt-1.5 flex flex-wrap items-center gap-4">
                        <flux:checkbox wire:model.live="includeInvoices" label="{{ __('Invoices') }}" />
                        <flux:checkbox wire:model.live="includeDebitNotes" label="{{ __('Debit Notes') }}" />
                        <flux:checkbox wire:model.live="includePayouts" label="{{ __('Payouts') }}" />
                    </div>
                    <flux:error name="includeInvoices" />
                </div>

                <flux:input
                    wire:model.live.debounce.400ms="minBalance"
                    type="number"
                    step="0.01"
                    min="0"
                    prefix="£"
                    :label="__('Minimum outstanding balance (optional)')"
                    placeholder="0.00"
                />

                <div>
                    <flux:label>{{ __('Action') }}</flux:label>
                    <flux:radio.group wire:model.live="action" class="mt-1.5 flex flex-col gap-2">
                        <flux:radio value="print" label="{{ __('Print') }}" />
                        <flux:radio value="pdf" label="{{ __('To PDF') }}" />
                        <flux:radio value="email" label="{{ __('Send Email') }}" />
                    </flux:radio.group>
                </div>

                @if($action === 'email')
                    <div x-data="emailTagInput($wire, @js($statementEmails), 'statementEmails')" wire:ignore>
                        <flux:label>{{ __('Recipients') }} <span class="text-rose-500">*</span></flux:label>
                        <div
                            class="mt-1 flex min-h-[38px] flex-wrap gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 dark:border-white/15 dark:bg-zinc-800"
                            x-on:click="$refs.tagInput.focus()"
                        >
                            <template x-for="(tag, i) in tags" :key="i">
                                <span class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30">
                                    <span x-text="tag"></span>
                                    <button type="button" x-on:click.stop="removeTag(i)" class="flex items-center text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-200">
                                        <svg class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    </button>
                                </span>
                            </template>
                            <input
                                x-ref="tagInput"
                                x-model="input"
                                x-on:keydown="onKeydown($event)"
                                x-on:blur="addTag()"
                                x-on:paste="onPaste($event)"
                                type="text"
                                placeholder="Type email and press Enter…"
                                class="min-w-[160px] flex-1 border-0 bg-transparent p-0 text-sm text-zinc-900 placeholder-zinc-400 outline-none focus:ring-0 dark:text-white"
                            />
                        </div>
                        <p x-show="error" x-text="error" class="mt-1 text-xs text-rose-500"></p>
                    </div>
                    <flux:error name="statementEmails" />
                    <flux:error name="statementEmails.*" />

                    <flux:textarea
                        wire:model="statementNotes"
                        :label="__('Additional Notes')"
                        :placeholder="__('Optional message to include in the email…')"
                        rows="3"
                    />
                    <flux:error name="statementNotes" />
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    wire:click="generate"
                    wire:loading.attr="disabled"
                    wire:target="generate"
                >
                    @if($action === 'print')
                        {{ __('Print Statement') }}
                    @elseif($action === 'pdf')
                        {{ __('Download PDF') }}
                    @else
                        {{ __('Send Statement') }}
                    @endif
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
