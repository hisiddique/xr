<?php

use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Traits\WithPerPage;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Exports')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    public int $perPage = 25;

    public ?string $backUrl = null;

    /** @var array<int, int> */
    public array $selected = [];

    public bool $selectAll = false;

    /** @var array<int, int> */
    public array $deletingIds = [];

    public function mount(): void
    {
        if (session()->has('toast')) {
            Flux::toast(variant: 'success', text: session('toast'));
        }

        if (session()->has('error')) {
            Flux::toast(variant: 'danger', text: session('error'));
        }

        $referer = request()->header('referer');

        if ($referer
            && parse_url($referer, PHP_URL_HOST) === request()->getHost()
            && rtrim(parse_url($referer, PHP_URL_PATH) ?? '', '/') !== rtrim(parse_url(url()->current(), PHP_URL_PATH) ?? '', '/')
        ) {
            $this->backUrl = $referer;
        }
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->deletableJobIds->all() : [];
    }

    public function cancel(int $exportJobId): void
    {
        ExportJob::query()
            ->where('id', $exportJobId)
            ->where('created_by', auth()->id())
            ->whereIn('status', [ExportJobStatus::Pending, ExportJobStatus::Running])
            ->update(['cancelled_at' => now()]);
    }

    public function confirmDelete(?int $exportJobId = null): void
    {
        $this->deletingIds = $exportJobId !== null ? [$exportJobId] : $this->selected;

        if (empty($this->deletingIds)) {
            return;
        }

        Flux::modal('confirm-delete-exports')->show();
    }

    public function deleteConfirmed(): void
    {
        $exports = ExportJob::query()
            ->where('created_by', auth()->id())
            ->whereIn('id', $this->deletingIds)
            ->whereNotIn('status', [ExportJobStatus::Pending, ExportJobStatus::Running])
            ->get();

        foreach ($exports as $export) {
            if ($export->download_path) {
                Storage::disk('local')->delete($export->download_path);
            }

            $export->delete();
        }

        $this->selected = array_values(array_diff($this->selected, $this->deletingIds));
        $this->selectAll = false;
        $this->deletingIds = [];

        Flux::modal('confirm-delete-exports')->close();
        Flux::toast(variant: 'success', text: __(':count export(s) deleted.', ['count' => $exports->count()]));
    }

    #[Computed]
    public function exportJobs()
    {
        return ExportJob::query()
            ->where('created_by', auth()->id())
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function deletableJobIds()
    {
        return $this->exportJobs
            ->filter(fn (ExportJob $export) => ! in_array($export->status, [ExportJobStatus::Pending, ExportJobStatus::Running], true))
            ->pluck('id');
    }

    #[Computed]
    public function hasActiveJobs(): bool
    {
        return $this->exportJobs->contains(
            fn (ExportJob $export) => in_array($export->status, [ExportJobStatus::Pending, ExportJobStatus::Running], true)
        );
    }
}; ?>

<div
    class="flex flex-col gap-4"
    @if ($this->hasActiveJobs)
        wire:poll.2s="$refresh"
    @endif
>

    <x-ui.page-header
        title="Exports"
        subtitle="Track and download your report exports."
    >
        <x-slot:action>
            <div class="flex items-center gap-2">
                @if($backUrl)
                    <flux:button size="sm" variant="ghost" icon="arrow-left" :href="$backUrl" wire:navigate>
                        Back
                    </flux:button>
                @endif

                @if(count($selected) > 0)
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete()">
                        Delete Selected ({{ count($selected) }})
                    </flux:button>
                @endif
            </div>
        </x-slot:action>
    </x-ui.page-header>

    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->exportJobs->isEmpty())
            <x-ui.empty-state
                icon="arrow-down-tray"
                title="No exports yet"
                description="Exports you start from a report page will appear here."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                <flux:checkbox wire:model.live="selectAll" :disabled="$this->deletableJobIds->isEmpty()" />
                            </th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Report</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Format</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Progress</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Created</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->exportJobs as $export)
                            @php
                                $percent = $export->rows_total > 0
                                    ? min(100, (int) round($export->rows_processed / $export->rows_total * 100))
                                    : 0;
                                $isTerminal = ! in_array($export->status, [ExportJobStatus::Pending, ExportJobStatus::Running], true);
                            @endphp
                            <tr wire:key="export-{{ $export->id }}">
                                <td class="px-4 py-2">
                                    @if($isTerminal)
                                        <flux:checkbox wire:model.live="selected" value="{{ $export->id }}" />
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ Str::headline($export->type) }}</td>
                                <td class="px-4 py-2 uppercase text-zinc-500 dark:text-zinc-400">{{ $export->format }}</td>
                                <td class="px-4 py-2">
                                    <flux:badge
                                        size="sm"
                                        :color="match ($export->status) {
                                            ExportJobStatus::Pending => 'zinc',
                                            ExportJobStatus::Running => 'blue',
                                            ExportJobStatus::Completed => 'green',
                                            ExportJobStatus::Failed => 'red',
                                            ExportJobStatus::Cancelled => 'zinc',
                                        }"
                                    >
                                        {{ $export->cancelled_at && $export->status === ExportJobStatus::Running ? 'Cancelling…' : $export->status->label() }}
                                    </flux:badge>
                                    @if($export->status === ExportJobStatus::Failed && $export->error)
                                        <p class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ $export->error }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if(! $isTerminal)
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-28 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                <div class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ $percent }}%"></div>
                                            </div>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $percent }}%</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $export->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($export->status === ExportJobStatus::Completed && $export->download_path)
                                            <flux:button size="sm" icon="arrow-down-tray" :href="route('exports.download', $export)">Download</flux:button>
                                        @elseif(! $isTerminal && ! $export->cancelled_at)
                                            <flux:button size="sm" variant="danger" wire:click="cancel({{ $export->id }})">Cancel</flux:button>
                                        @endif

                                        @if($isTerminal)
                                            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDelete({{ $export->id }})" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <flux:pagination :paginator="$this->exportJobs" class="px-6" />
        @endif
    </div>

    <flux:modal name="confirm-delete-exports" focusable class="max-w-md">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Delete Export(s)') }}</flux:heading>
                <flux:subheading>
                    {{ __('This will permanently delete :count export(s) and their downloaded files. This cannot be undone.', ['count' => count($deletingIds)]) }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteConfirmed">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
