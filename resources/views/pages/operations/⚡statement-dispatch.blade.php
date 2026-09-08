<?php

use App\Models\CustomerGroup;
use App\Models\LookupPaymentMethod;
use App\Models\Setting;
use App\Models\StatementSchedule;
use App\Models\SupplierGroup;
use App\Services\NextRunCalculator;
use App\StatementFrequency;
use App\StatementScheduleStatus;
use App\Support\StatementPeriod;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Statement Dispatch')] class extends Component {
    public ?StatementSchedule $schedule = null;

    public string $name = '';
    public string $model_type = 'customer';
    public string $frequency = 'monthly';
    public ?int $target_group_id = null;
    public ?int $exclusion_group_id = null;
    public ?int $day_of_month = 1;
    public ?int $day_of_week = 1;
    public string $anchor_date = '';
    public string $run_time = '09:00';

    public string $preset = 'last_month';
    public string $date_from = '';
    public string $date_to = '';
    public bool $outstanding_only = true;
    public string $min_balance = '';
    public bool $include_invoices = true;
    public bool $include_credit_notes = false;
    public bool $include_write_offs = false;
    public bool $include_payments = false;
    public bool $include_debit_notes = false;
    public bool $include_payouts = false;

    /** @var array<int, string> */
    public array $payment_methods = [];

    public bool $notify_enabled = false;

    /** @var array<int, string> */
    public array $notify_emails = [];

    public string $notes = '';

    public function mount(): void
    {
        if ($this->schedule) {
            $schedule = $this->schedule;

            $this->name = $schedule->name;
            $this->model_type = $schedule->model_type->value;
            $this->frequency = $schedule->frequency->value;
            $this->target_group_id = $schedule->isCustomer() ? $schedule->customer_group_id : $schedule->supplier_group_id;
            $this->exclusion_group_id = $schedule->isCustomer() ? $schedule->exclude_customer_group_id : $schedule->exclude_supplier_group_id;
            $this->day_of_month = $schedule->day_of_month ?? 1;
            $this->day_of_week = $schedule->day_of_week ?? 1;
            $this->anchor_date = $schedule->anchor_date?->toDateString() ?? '';
            $this->run_time = substr($schedule->run_time ?: '09:00', 0, 5);
            $this->notify_enabled = (bool) $schedule->notify_enabled;
            $this->notify_emails = $schedule->notify_emails ?? [];
            $this->notes = $schedule->notes ?? '';

            $rules = $schedule->rules ?? [];
            $this->preset = $rules['preset'] ?? 'last_month';
            $this->date_from = $rules['date_from'] ?? '';
            $this->date_to = $rules['date_to'] ?? '';
            $this->outstanding_only = $rules['outstanding_only'] ?? true;
            $this->min_balance = isset($rules['min_balance']) && $rules['min_balance'] !== null ? (string) $rules['min_balance'] : '';
            $this->include_invoices = $rules['include_invoices'] ?? true;
            $this->include_credit_notes = $rules['include_credit_notes'] ?? false;
            $this->include_write_offs = $rules['include_write_offs'] ?? false;
            $this->include_payments = $rules['include_payments'] ?? false;
            $this->include_debit_notes = $rules['include_debit_notes'] ?? false;
            $this->include_payouts = $rules['include_payouts'] ?? false;
            $this->payment_methods = array_map('strval', $rules['payment_methods'] ?? []);

            return;
        }

        if (request('model_type') === 'supplier') {
            $this->model_type = 'supplier';
        }
    }

    public function updatedModelType(): void
    {
        $this->target_group_id = null;
        $this->exclusion_group_id = null;
        $this->payment_methods = [];
    }

    public function updatedIncludePayments(bool $value): void
    {
        if (! $value) {
            $this->payment_methods = [];
        }
    }

    public function updatedPreset(): void
    {
        if ($this->preset === 'custom') {
            return;
        }

        $period = StatementPeriod::fromPreset($this->preset);
        $this->date_from = $period->from ?? '';
        $this->date_to = $period->to ?? '';
    }

    public function updatedNotifyEnabled($value): void
    {
        if ($value && $this->notify_emails === []) {
            $this->notify_emails = array_values(array_filter([
                Setting::get('company_email') ?: config('mail.from.address'),
            ]));
        }
    }

    #[Computed]
    public function groups()
    {
        return $this->model_type === 'supplier'
            ? SupplierGroup::orderBy('name')->get()
            : CustomerGroup::orderBy('name')->get();
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    public function save(): void
    {
        $this->persist(false);
    }

    public function saveAndActivate(): void
    {
        $this->persist(true);
    }

    protected function persist(bool $activate): void
    {
        $frequencyValues = implode(',', array_map(fn (StatementFrequency $case) => $case->value, StatementFrequency::cases()));

        $rules = [
            'name' => 'required|string|max:255',
            'model_type' => 'required|in:customer,supplier',
            'frequency' => 'required|in:'.$frequencyValues,
            'target_group_id' => 'nullable|integer',
            'exclusion_group_id' => 'nullable|integer',
            'run_time' => 'required|date_format:H:i',
            'preset' => 'required|string',
            'min_balance' => 'nullable|numeric|min:0',
            'payment_methods' => 'array',
            'payment_methods.*' => 'integer|exists:lookup_payment_methods,id',
            'notify_emails' => 'array',
            'notify_emails.*' => 'email',
            'notes' => 'nullable|string|max:2000',
        ];

        if ($this->frequency === 'monthly') {
            $rules['day_of_month'] = 'required|integer|between:1,28';
        }

        if ($this->frequency === 'weekly') {
            $rules['day_of_week'] = 'required|integer|between:1,7';
        }

        if (in_array($this->frequency, ['quarterly', 'one_time'], true)) {
            $rules['anchor_date'] = $this->frequency === 'one_time'
                ? 'required|date|after_or_equal:today'
                : 'required|date';
        }

        $this->validate($rules);

        if ($this->frequency === 'one_time') {
            $runAt = \Carbon\Carbon::parse($this->anchor_date.' '.$this->run_time, config('app.timezone'));

            if ($runAt->isPast()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'run_time' => __('That date and time is already in the past (app timezone :tz). Pick a future time.', ['tz' => config('app.timezone')]),
                ]);
            }
        }

        $isCustomer = $this->model_type === 'customer';

        $ruleData = [
            'preset' => $this->preset,
            'date_from' => $this->preset === 'custom' ? ($this->date_from ?: null) : null,
            'date_to' => $this->preset === 'custom' ? ($this->date_to ?: null) : null,
            'outstanding_only' => $this->outstanding_only,
            'min_balance' => $this->min_balance === '' ? null : (float) $this->min_balance,
            'include_invoices' => $this->include_invoices,
        ];

        if ($isCustomer) {
            $ruleData['include_credit_notes'] = $this->include_credit_notes;
            $ruleData['include_write_offs'] = $this->include_write_offs;
            $ruleData['include_payments'] = $this->include_payments;
            $ruleData['payment_methods'] = $this->include_payments
                ? array_values(array_map('intval', $this->payment_methods))
                : [];
        } else {
            $ruleData['include_debit_notes'] = $this->include_debit_notes;
            $ruleData['include_payouts'] = $this->include_payouts;
        }

        $data = [
            'name' => $this->name,
            'model_type' => $this->model_type,
            'frequency' => $this->frequency,
            'customer_group_id' => $isCustomer ? $this->target_group_id : null,
            'supplier_group_id' => $isCustomer ? null : $this->target_group_id,
            'exclude_customer_group_id' => $isCustomer ? $this->exclusion_group_id : null,
            'exclude_supplier_group_id' => $isCustomer ? null : $this->exclusion_group_id,
            'run_time' => $this->run_time,
            'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
            'day_of_week' => $this->frequency === 'weekly' ? $this->day_of_week : null,
            'anchor_date' => in_array($this->frequency, ['quarterly', 'one_time'], true) ? $this->anchor_date : null,
            'rules' => $ruleData,
            'notify_enabled' => $this->notify_enabled,
            'notify_emails' => array_values($this->notify_emails),
            'notes' => $this->notes ?: null,
        ];

        if ($this->schedule) {
            $status = $activate ? StatementScheduleStatus::Active : $this->schedule->status;
            $this->schedule->update($data + ['status' => $status]);
            $schedule = $this->schedule;
        } else {
            $status = $activate ? StatementScheduleStatus::Active : StatementScheduleStatus::Draft;
            $schedule = StatementSchedule::create($data + [
                'status' => $status,
                'created_by' => auth()->id(),
            ]);
        }

        $nextRunAt = $status === StatementScheduleStatus::Active
            ? app(NextRunCalculator::class)->nextRunAt($schedule->fresh())
            : null;

        if ($status === StatementScheduleStatus::Active && $nextRunAt === null) {
            $schedule->update(['status' => StatementScheduleStatus::Draft, 'next_run_at' => null]);
            Flux::toast(variant: 'warning', text: __('Saved as draft — no upcoming run time could be worked out for this schedule.'));
            $this->redirect(route('operations.schedules'), navigate: true);

            return;
        }

        $schedule->update(['next_run_at' => $nextRunAt]);

        Flux::toast(variant: 'success', text: $this->schedule ? __('Schedule updated.') : __('Schedule created.'));
        $this->redirect(route('operations.schedules'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="{{ $schedule ? 'Edit: '.$schedule->name : 'New Statement Dispatch' }}"
        subtitle="{{ $schedule ? 'Update this automated statement schedule.' : 'Schedule automated statements for a group of customers or suppliers.' }}"
    >
        <x-slot:action>
            <flux:button variant="ghost" icon="arrow-left" :href="route('operations.schedules')" wire:navigate>
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

            {{-- Section: Basics --}}
            <div class="px-4 py-4">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Basics</h2>

                <div class="grid gap-4 md:grid-cols-2" x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                    <div>
                        <flux:input wire:model="name" :label="__('Schedule name')" placeholder="e.g. Monthly customer statements" required />
                        <flux:error name="name" />
                    </div>
                    <div>
                        <flux:select wire:model.live="model_type" :label="__('Statement subject')" :disabled="$schedule !== null">
                            <flux:select.option value="customer">{{ __('Customers') }}</flux:select.option>
                            <flux:select.option value="supplier">{{ __('Suppliers') }}</flux:select.option>
                        </flux:select>
                        <flux:error name="model_type" />
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <flux:select wire:model="target_group_id" :label="__('Target group')">
                            <flux:select.option value="">{{ __('— All active accounts —') }}</flux:select.option>
                            @foreach($this->groups as $group)
                                <flux:select.option :value="$group->id">{{ $group->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="target_group_id" />
                    </div>
                    <div>
                        <flux:select wire:model="exclusion_group_id" :label="__('Exclusion group')">
                            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                            @foreach($this->groups as $group)
                                <flux:select.option :value="$group->id">{{ $group->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="exclusion_group_id" />
                    </div>
                </div>
            </div>

            {{-- Section: Statement rules --}}
            <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Statement rules</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <flux:label>{{ __('Period') }}</flux:label>
                        <flux:select wire:model.live="preset" class="mt-1.5">
                            @foreach(StatementPeriod::presets() as $key => $label)
                                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="preset" />
                    </div>
                    <div>
                        <flux:input
                            wire:model="min_balance"
                            type="number"
                            step="0.01"
                            min="0"
                            prefix="£"
                            :label="__('Minimum outstanding balance (optional)')"
                            placeholder="0.00"
                        />
                        <flux:error name="min_balance" />
                    </div>
                </div>

                @if($preset === 'custom')
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="date_from" type="date" :label="__('From')" />
                        <flux:input wire:model="date_to" type="date" :label="__('To')" />
                    </div>
                @endif

                <div class="mt-4 flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-white/10">
                    <flux:label>{{ __('Outstanding invoices only') }}</flux:label>
                    <flux:switch wire:model.live="outstanding_only" />
                </div>

                <div class="mt-4">
                    <flux:label>{{ __('Include') }}</flux:label>
                    <div class="mt-1.5 flex flex-wrap items-center gap-4">
                        <flux:checkbox wire:model="include_invoices" label="{{ __('Invoices') }}" />
                        @if($model_type === 'customer')
                            <flux:checkbox wire:model="include_credit_notes" label="{{ __('Credit Notes') }}" />
                            <flux:checkbox wire:model="include_write_offs" label="{{ __('Write-Offs') }}" />
                            <flux:checkbox wire:model.live="include_payments" label="{{ __('Payments') }}" />
                        @else
                            <flux:checkbox wire:model="include_debit_notes" label="{{ __('Debit Notes') }}" />
                            <flux:checkbox wire:model="include_payouts" label="{{ __('Payouts') }}" />
                        @endif
                    </div>
                    <flux:error name="include_invoices" />
                </div>

                @if($model_type === 'customer' && $include_payments && $this->paymentMethodOptions->isNotEmpty())
                    <div class="mt-4">
                        <flux:label>{{ __('Payment methods (optional)') }}</flux:label>
                        <flux:checkbox.group wire:model="payment_methods" class="mt-1.5">
                            <div class="flex flex-wrap items-center gap-4">
                                @foreach($this->paymentMethodOptions as $method)
                                    <flux:checkbox :value="(string) $method->id" :label="$method->name" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                        <flux:description>{{ __('Leave all unchecked to include every method.') }}</flux:description>
                        <flux:error name="payment_methods" />
                        <flux:error name="payment_methods.*" />
                    </div>
                @endif
            </div>

            {{-- Section: Schedule --}}
            <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Schedule</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <flux:select wire:model.live="frequency" :label="__('Frequency')">
                            @foreach(StatementFrequency::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="frequency" />
                    </div>

                    @if($frequency === 'monthly')
                        <div>
                            <flux:input wire:model="day_of_month" type="number" min="1" max="28" :label="__('Day of month')" />
                            <flux:error name="day_of_month" />
                        </div>
                    @endif

                    @if($frequency === 'weekly')
                        <div>
                            <flux:select wire:model="day_of_week" :label="__('Day of week')">
                                <flux:select.option :value="1">{{ __('Monday') }}</flux:select.option>
                                <flux:select.option :value="2">{{ __('Tuesday') }}</flux:select.option>
                                <flux:select.option :value="3">{{ __('Wednesday') }}</flux:select.option>
                                <flux:select.option :value="4">{{ __('Thursday') }}</flux:select.option>
                                <flux:select.option :value="5">{{ __('Friday') }}</flux:select.option>
                                <flux:select.option :value="6">{{ __('Saturday') }}</flux:select.option>
                                <flux:select.option :value="7">{{ __('Sunday') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="day_of_week" />
                        </div>
                    @endif

                    @if(in_array($frequency, ['quarterly', 'one_time']))
                        <div>
                            <flux:input
                                wire:model="anchor_date"
                                type="date"
                                :label="__('Anchor date')"
                                min="{{ $frequency === 'one_time' ? now()->toDateString() : '' }}"
                            />
                            <flux:error name="anchor_date" />
                        </div>
                    @endif
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <flux:input wire:model="run_time" type="time" :label="__('Run time')" required />
                        <flux:description>{{ __('Uses the application timezone (:tz).', ['tz' => config('app.timezone')]) }}</flux:description>
                        <flux:error name="run_time" />
                    </div>
                </div>
            </div>

            {{-- Section: Notifications --}}
            <div class="border-t border-zinc-200/70 px-4 py-4 dark:border-white/10">
                <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Notifications</h2>

                <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-white/10">
                    <flux:label>{{ __('Email a run summary when a dispatch succeeds or fails') }}</flux:label>
                    <flux:switch wire:model.live="notify_enabled" />
                </div>

                @if($notify_enabled)
                    <div class="mt-4 space-y-4">
                        <div x-data="emailTagInput($wire, @js($notify_emails), 'notify_emails')" wire:ignore>
                            <flux:label>{{ __('Send the summary to') }}</flux:label>
                            <flux:description>{{ __('A dispatch report — totals, plus any failed recipients and why. Not the statement PDFs themselves.') }}</flux:description>
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
                        <flux:error name="notify_emails" />
                        <flux:error name="notify_emails.*" />

                        <flux:textarea
                            wire:model="notes"
                            :label="__('Notes')"
                            :placeholder="__('Optional internal note for this schedule…')"
                            rows="3"
                        />
                        <flux:error name="notes" />
                    </div>
                @endif
            </div>

            {{-- Sticky footer actions --}}
            <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
                <x-ui.back-button :fallback="route('operations.schedules')" />
                <flux:button type="submit" variant="filled">{{ $schedule ? 'Save changes' : 'Save as draft' }}</flux:button>
                <flux:button type="button" wire:click="saveAndActivate" variant="primary">Save & activate</flux:button>
            </div>

        </form>

        <x-ui.form-shortcuts />

    </div>

    <x-ui.exit-confirm-modal />

</div>
