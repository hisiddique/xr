<?php

use App\Models\Customer;
use App\Services\CustomerStatementService;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Customer $customer;

    public string $preset = 'this_month';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $outstandingOnly = false;
    public string $minBalance = '';
    public string $action = 'print';

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
        [$this->dateFrom, $this->dateTo] = match ($this->preset) {
            'yesterday' => [Carbon::yesterday()->toDateString(), Carbon::yesterday()->toDateString()],
            'this_week' => [Carbon::now()->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
            'last_week' => [Carbon::now()->subWeek()->startOfWeek()->toDateString(), Carbon::now()->subWeek()->endOfWeek()->toDateString()],
            'last_two_weeks' => [Carbon::now()->subWeeks(2)->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
            'this_month' => [Carbon::now()->startOfMonth()->toDateString(), Carbon::now()->endOfMonth()->toDateString()],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth()->toDateString(), Carbon::now()->subMonth()->endOfMonth()->toDateString()],
            'three_months_ago' => [Carbon::now()->subMonths(3)->startOfMonth()->toDateString(), Carbon::now()->subMonths(3)->endOfMonth()->toDateString()],
            'six_months_ago' => [Carbon::now()->subMonths(6)->startOfMonth()->toDateString(), Carbon::now()->subMonths(6)->endOfMonth()->toDateString()],
            'this_year' => [Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfYear()->toDateString()],
            'last_year' => [Carbon::now()->subYear()->startOfYear()->toDateString(), Carbon::now()->subYear()->endOfYear()->toDateString()],
            'custom' => [$this->dateFrom, $this->dateTo],
            default => ['', ''],
        };
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
        ];
    }

    public function generate(): void
    {
        if ($this->action === 'email') {
            $this->validate([
                'statementEmails' => 'required|array|min:1',
                'statementEmails.*' => 'email|max:254',
                'statementNotes' => 'nullable|string|max:2000',
            ]);

            try {
                app(CustomerStatementService::class)->sendStatementEmail(
                    $this->customer,
                    $this->filters(),
                    $this->statementEmails,
                    $this->statementNotes ?: null,
                );

                Flux::modal('statement-'.$this->customer->id)->close();
                Flux::toast(variant: 'success', text: __('Statement sent to :recipient.', ['recipient' => $this->statementEmails[0]]));
            } catch (\Throwable $e) {
                $this->addError('statementEmails', $e->getMessage());
            }

            return;
        }

        $url = route('customers.statement.export', array_merge(
            ['customer' => $this->customer, 'inline' => $this->action === 'print' ? 1 : 0],
            $this->filters(),
        ));

        Flux::modal('statement-'.$this->customer->id)->close();
        $this->dispatch('statement-ready', url: $url, action: $this->action);
    }
}; ?>

<div x-data x-on:statement-ready.window="$event.detail.action === 'print' ? window.printPdfDocument($event.detail.url) : (window.location.href = $event.detail.url)">
    <flux:button
        variant="ghost"
        icon="document-text"
        size="sm"
        x-on:click="$flux.modal('statement-{{ $customer->id }}').show()"
    >
        {{ __('Generate Statement') }}
    </flux:button>

    <flux:modal name="statement-{{ $customer->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Generate Customer Statement') }}</flux:heading>
                <flux:subheading>
                    {{ __('Statement of account for :company.', ['company' => $customer->company_name]) }}
                </flux:subheading>
            </div>

            <div class="space-y-4">
                <div>
                    <flux:label>{{ __('Period') }}</flux:label>
                    <flux:select wire:model.live="preset" class="mt-1.5">
                        <flux:select.option value="yesterday">{{ __('Yesterday') }}</flux:select.option>
                        <flux:select.option value="this_week">{{ __('This Week') }}</flux:select.option>
                        <flux:select.option value="last_week">{{ __('Last Week') }}</flux:select.option>
                        <flux:select.option value="last_two_weeks">{{ __('Last Two Weeks') }}</flux:select.option>
                        <flux:select.option value="this_month">{{ __('This Month') }}</flux:select.option>
                        <flux:select.option value="last_month">{{ __('Last Month') }}</flux:select.option>
                        <flux:select.option value="three_months_ago">{{ __('3 Months Ago') }}</flux:select.option>
                        <flux:select.option value="six_months_ago">{{ __('6 Months Ago') }}</flux:select.option>
                        <flux:select.option value="this_year">{{ __('This Year') }}</flux:select.option>
                        <flux:select.option value="last_year">{{ __('Last Year') }}</flux:select.option>
                        <flux:select.option value="custom">{{ __('Custom') }}</flux:select.option>
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
