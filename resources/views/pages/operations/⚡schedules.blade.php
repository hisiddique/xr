<?php

use App\Jobs\ProcessStatementDispatchRunJob;
use App\Models\StatementSchedule;
use App\Services\NextRunCalculator;
use App\Services\StatementDispatchService;
use App\StatementScheduleStatus;
use App\Traits\WithPerPage;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Dispatch Schedules')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    public int $perPage = 25;

    #[Url]
    public string $tab = 'all';

    public ?int $deletingId = null;

    public function mount(): void
    {
        if (session()->has('toast')) {
            Flux::toast(variant: 'success', text: session('toast'));
        }

        if (session()->has('error')) {
            Flux::toast(variant: 'danger', text: session('error'));
        }
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function pause(int $id): void
    {
        $schedule = StatementSchedule::findOrFail($id);
        $schedule->update(['status' => 'paused']);

        Flux::toast(variant: 'success', text: __('Schedule paused.'));
    }

    public function resume(int $id): void
    {
        $schedule = StatementSchedule::findOrFail($id);
        $nextRunAt = app(NextRunCalculator::class)->nextRunAt($schedule, now());

        if ($nextRunAt === null) {
            $schedule->update(['status' => 'draft', 'next_run_at' => null]);
            Flux::toast(variant: 'warning', text: __('Kept as draft — no upcoming run time (the date/time may be in the past).'));

            return;
        }

        $schedule->update(['status' => 'active', 'next_run_at' => $nextRunAt]);
        Flux::toast(variant: 'success', text: __('Schedule activated.'));
    }

    public function runNow(int $id): void
    {
        $schedule = StatementSchedule::findOrFail($id);

        if ($schedule->runs()->whereIn('status', ['queued', 'processing'])->exists()) {
            Flux::toast(variant: 'warning', text: __('A run for this schedule is already in progress.'));

            return;
        }

        $run = app(StatementDispatchService::class)->createManualRun($schedule, auth()->id());
        ProcessStatementDispatchRunJob::dispatch($run->id);

        Flux::toast(variant: 'success', text: __('Dispatch queued.'));
    }

    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        try {
            StatementSchedule::findOrFail($this->deletingId)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            Flux::modal('delete-schedule')->close();
            Flux::toast(variant: 'danger', text: __('Could not delete — remove its dispatch history first.'));

            return;
        }

        $this->deletingId = null;

        Flux::modal('delete-schedule')->close();
        Flux::toast(variant: 'success', text: __('Schedule deleted.'));
    }

    #[Computed]
    public function schedules()
    {
        return StatementSchedule::query()
            ->when($this->tab !== 'all', fn ($q) => $q->where('status', $this->tab))
            ->orderByRaw('next_run_at is null, next_run_at asc')
            ->paginate($this->perPage);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'all' => StatementSchedule::count(),
            'active' => StatementSchedule::where('status', 'active')->count(),
            'paused' => StatementSchedule::where('status', 'paused')->count(),
            'draft' => StatementSchedule::where('status', 'draft')->count(),
            'completed' => StatementSchedule::where('status', 'completed')->count(),
        ];
    }

    #[Computed]
    public function deletingSchedule(): ?StatementSchedule
    {
        return $this->deletingId ? StatementSchedule::find($this->deletingId) : null;
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Dispatch Schedules"
        subtitle="Scheduled statement runs for customers and suppliers"
    >
        <x-slot:action>
            <div class="flex flex-wrap items-center gap-2">
                @can('statementdispatch-log')
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="paper-airplane"
                        :href="route('operations.dispatch-log')"
                        wire:navigate
                    >
                        Dispatch logs
                    </flux:button>
                @endcan
                @can('statementdispatch-create')
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        :href="route('operations.statement-dispatch.create')"
                        wire:navigate
                    >
                        Customer schedule
                    </flux:button>
                    <flux:button
                        size="sm"
                        icon="plus"
                        :href="route('operations.statement-dispatch.create', ['model_type' => 'supplier'])"
                        wire:navigate
                    >
                        Supplier schedule
                    </flux:button>
                @endcan
            </div>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-wrap gap-2">
        <flux:button size="sm" :variant="$tab === 'all' ? 'primary' : 'ghost'" wire:click="setTab('all')">
            All ({{ $this->counts['all'] }})
        </flux:button>
        <flux:button size="sm" :variant="$tab === 'active' ? 'primary' : 'ghost'" wire:click="setTab('active')">
            Active ({{ $this->counts['active'] }})
        </flux:button>
        <flux:button size="sm" :variant="$tab === 'paused' ? 'primary' : 'ghost'" wire:click="setTab('paused')">
            Paused ({{ $this->counts['paused'] }})
        </flux:button>
        <flux:button size="sm" :variant="$tab === 'draft' ? 'primary' : 'ghost'" wire:click="setTab('draft')">
            Draft ({{ $this->counts['draft'] }})
        </flux:button>
        <flux:button size="sm" :variant="$tab === 'completed' ? 'primary' : 'ghost'" wire:click="setTab('completed')">
            Completed ({{ $this->counts['completed'] }})
        </flux:button>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->schedules->isEmpty())
            <x-ui.empty-state
                icon="clock"
                title="No schedules"
                description="Create a statement dispatch schedule to get started."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Type</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Target group</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Frequency</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Last run</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Next run</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->schedules as $schedule)
                            <tr wire:key="schedule-{{ $schedule->id }}">
                                <td class="px-4 py-2 font-medium text-zinc-800 dark:text-zinc-200">{{ $schedule->name }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $schedule->model_type->ringColor() }}">
                                        {{ $schedule->model_type->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ optional($schedule->targetGroup())->name ?? __('All active accounts') }}</td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $schedule->frequency->label() }}</td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $schedule->last_run_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $schedule->next_run_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $schedule->status->ringColor() }}">
                                        {{ $schedule->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('statementdispatch-run')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="play"
                                                wire:click="runNow({{ $schedule->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="runNow"
                                            >
                                                Run now
                                            </flux:button>
                                        @endcan

                                        @can('statementdispatch-edit')
                                            @if($schedule->status === \App\StatementScheduleStatus::Active)
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="pause"
                                                    wire:click="pause({{ $schedule->id }})"
                                                >
                                                    Pause
                                                </flux:button>
                                            @elseif(in_array($schedule->status, [\App\StatementScheduleStatus::Paused, \App\StatementScheduleStatus::Draft], true))
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="play-circle"
                                                    wire:click="resume({{ $schedule->id }})"
                                                >
                                                    {{ $schedule->status === \App\StatementScheduleStatus::Draft ? 'Activate' : 'Resume' }}
                                                </flux:button>
                                            @endif

                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="pencil-square"
                                                :href="route('operations.statement-dispatch.edit', $schedule)"
                                                wire:navigate
                                            />
                                        @endcan

                                        @can('statementdispatch-delete')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="$set('deletingId', {{ $schedule->id }})"
                                                x-on:click="$flux.modal('delete-schedule').show()"
                                                class="text-rose-500 hover:text-rose-600"
                                            />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <flux:pagination :paginator="$this->schedules" class="px-6" />
        @endif
    </div>

    <flux:modal
        name="delete-schedule"
        focusable
        class="max-w-sm"
        @close="$wire.set('deletingId', null)"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Delete ":name"?', ['name' => $this->deletingSchedule?->name]) }}</flux:heading>
                <flux:subheading>
                    {{ __('The schedule is removed but its run history is kept.') }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="delete">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
