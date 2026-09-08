<?php

use App\Jobs\ProcessStatementDispatchRunJob;
use App\Models\StatementDispatchRun;
use App\Services\StatementDispatchService;
use App\StatementRunStatus;
use App\Traits\WithPerPage;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Dispatch Log')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    public int $perPage = 20;

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $typeFilter = 'all';

    public ?int $expandedId = null;

    public function mount(): void
    {
        if (session()->has('toast')) {
            Flux::toast(variant: 'success', text: session('toast'));
        }

        if (session()->has('error')) {
            Flux::toast(variant: 'danger', text: session('error'));
        }
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function runs()
    {
        return StatementDispatchRun::query()
            ->with('schedule:id')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('model_type', $this->typeFilter))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function expandedRun(): ?StatementDispatchRun
    {
        if (! $this->expandedId) {
            return null;
        }

        return StatementDispatchRun::with(['items' => fn ($q) => $q->orderBy('status')->orderBy('recipient_name')])
            ->find($this->expandedId);
    }

    #[Computed]
    public function hasActiveRuns(): bool
    {
        return StatementDispatchRun::whereIn('status', ['queued', 'processing'])->exists();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function retryFailed(int $id): void
    {
        $parent = StatementDispatchRun::findOrFail($id);

        if ($parent->failed_count < 1) {
            return;
        }

        $run = app(StatementDispatchService::class)->createRetryRun($parent, auth()->id());

        ProcessStatementDispatchRunJob::dispatch($run->id);

        Flux::toast(
            variant: 'success',
            text: __('Retrying :n failed recipient(s).', ['n' => $parent->failed_count]),
        );
    }
}; ?>

<div
    @if ($this->hasActiveRuns) wire:poll.2s="$refresh" @endif
    class="flex flex-col gap-6"
>
    <x-ui.page-header
        title="Dispatch Log"
        subtitle="Statement dispatch run history"
    />

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="statusFilter" label="Status" class="w-56">
            <flux:select.option value="all">All statuses</flux:select.option>
            <flux:select.option value="completed">{{ \App\StatementRunStatus::Completed->label() }}</flux:select.option>
            <flux:select.option value="completed_with_errors">{{ \App\StatementRunStatus::CompletedWithErrors->label() }}</flux:select.option>
            <flux:select.option value="failed">{{ \App\StatementRunStatus::Failed->label() }}</flux:select.option>
            <flux:select.option value="processing">{{ \App\StatementRunStatus::Processing->label() }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="typeFilter" label="Type" class="w-44">
            <flux:select.option value="all">All types</flux:select.option>
            <flux:select.option value="customer">Customer</flux:select.option>
            <flux:select.option value="supplier">Supplier</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->runs->isEmpty())
            <x-ui.empty-state
                icon="paper-airplane"
                title="No dispatch runs yet"
                description="Runs appear here once a schedule fires or you dispatch one manually."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="w-10 px-4 py-2"></th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Schedule</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Type</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Trigger</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Period</th>
                            <th class="whitespace-nowrap px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider">
                                <span class="text-emerald-600 dark:text-emerald-400">Sent</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="text-rose-600 dark:text-rose-400">Failed</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="text-amber-600 dark:text-amber-500">Skipped</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="text-zinc-500 dark:text-zinc-400">Total</span>
                            </th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">When</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->runs as $run)
                            <tr wire:key="run-{{ $run->id }}" class="cursor-pointer hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]" wire:click="toggle({{ $run->id }})">
                                <td class="px-4 py-2">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        :icon="$expandedId === $run->id ? 'chevron-down' : 'chevron-right'"
                                        wire:click.stop="toggle({{ $run->id }})"
                                    />
                                </td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                    {{ $run->schedule_name }}
                                    @if($run->parent_run_id)
                                        <span class="block text-xs text-zinc-400 dark:text-zinc-500">retry of #{{ $run->parent_run_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $run->model_type->ringColor() }}">
                                        {{ $run->model_type->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $run->trigger->label() }}</td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $run->period_label ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 tabular-nums">
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $run->sent_count }}</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="font-semibold text-rose-600 dark:text-rose-400">{{ $run->failed_count }}</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="font-semibold text-amber-600 dark:text-amber-500">{{ $run->skipped_count }}</span><span class="text-zinc-300 dark:text-zinc-600"> / </span><span class="font-semibold text-zinc-500 dark:text-zinc-400">{{ $run->total_count }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $run->status->ringColor() }}">
                                        {{ $run->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $run->finished_at?->format('d M H:i') ?? $run->started_at?->format('d M H:i') ?? $run->created_at->format('d M H:i') }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @can('statementdispatch-run')
                                        @if($run->failed_count > 0 && $run->status->isTerminal())
                                            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click.stop="retryFailed({{ $run->id }})">
                                                Retry failed
                                            </flux:button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>

                            @if($run->status === \App\StatementRunStatus::Failed && $run->error)
                                <tr wire:key="run-{{ $run->id }}-error">
                                    <td colspan="9" class="px-4 pb-2 pt-0">
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ $run->error }}</p>
                                    </td>
                                </tr>
                            @endif

                            @if($expandedId === $run->id)
                                <tr wire:key="run-{{ $run->id }}-items">
                                    <td colspan="9" class="bg-zinc-50/60 px-4 py-3 dark:bg-white/[0.02]">
                                        @if($this->expandedRun && $this->expandedRun->items->isNotEmpty())
                                            <ul class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                                                @foreach($this->expandedRun->items as $item)
                                                    <li wire:key="item-{{ $item->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $item->status->ringColor() }}">
                                                            {{ $item->status->label() }}
                                                        </span>
                                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                                            {{ $item->recipient_name }} — {{ $item->recipient_email ?: '—' }}
                                                        </span>
                                                        @if($item->status === \App\StatementRunItemStatus::Skipped)
                                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                                                Skipped — {{ $item->skip_reason === 'no_email' ? 'no email address' : 'no activity in period' }}
                                                            </span>
                                                        @elseif($item->status === \App\StatementRunItemStatus::Failed)
                                                            <span class="text-xs text-red-600 dark:text-red-400">{{ $item->error_message }}</span>
                                                        @elseif($item->status === \App\StatementRunItemStatus::Sent)
                                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                                                Sent {{ $item->sent_at?->format('d M H:i') }} · £{{ number_format((float) $item->outstanding_total, 2) }}
                                                            </span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="py-2 text-xs text-zinc-400 dark:text-zinc-500">No recipient items recorded for this run.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $this->runs->links() }}
        @endif
    </div>
</div>
