<?php

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use App\Jobs\RunArchiveCleanupJob;
use App\Jobs\RunArchiveJob;
use App\Models\ArchiveRun;
use App\Models\Setting;
use App\Services\Archive\ArchiveConnectionValidator;
use App\Services\Archive\ArchiveTableRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Data Archive')] class extends Component
{
    public string $connHost = '';

    public string $connPort = '3306';

    public string $connDatabase = '';

    public string $connUsername = '';

    public string $connPassword = '';

    public bool $editingConnection = false;

    /** @var array<int, string> */
    public array $selectedGroups = [];

    public bool $selectAll = false;

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $activeRunId = null;

    public ?int $cleanupSourceId = null;

    public function mount(): void
    {
        $this->connHost = (string) Setting::get('archive_db_host', '');
        $this->connPort = (string) Setting::get('archive_db_port', '3306');
        $this->connDatabase = (string) Setting::get('archive_db_database', '');
        $this->connUsername = (string) Setting::get('archive_db_username', '');

        $this->editingConnection = ! $this->connectionConfigured();

        $this->activeRunId = ArchiveRun::latest('id')->value('id');
    }

    public function updatedSelectAll(): void
    {
        $this->selectedGroups = $this->selectAll
            ? array_keys(app(ArchiveTableRegistry::class)->selectableGroups())
            : [];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function formCredentials(): array
    {
        return [
            'host' => trim($this->connHost),
            'port' => (int) ($this->connPort ?: 3306),
            'database' => trim($this->connDatabase),
            'username' => trim($this->connUsername),
            'password' => $this->connPassword !== ''
                ? $this->connPassword
                : rescue(fn () => Crypt::decryptString(Setting::get('archive_db_password', '')), ''),
        ];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function storedCredentials(): array
    {
        return [
            'host' => (string) Setting::get('archive_db_host', ''),
            'port' => (int) (Setting::get('archive_db_port', '3306') ?: 3306),
            'database' => (string) Setting::get('archive_db_database', ''),
            'username' => (string) Setting::get('archive_db_username', ''),
            'password' => rescue(fn () => Crypt::decryptString(Setting::get('archive_db_password', '')), ''),
        ];
    }

    private function anyRunActive(): bool
    {
        return ArchiveRun::whereIn('status', [ArchiveRunStatus::Pending, ArchiveRunStatus::Running])->exists();
    }

    private function connectionConfigured(): bool
    {
        return filled(Setting::get('archive_db_host'))
            && filled(Setting::get('archive_db_database'))
            && filled(Setting::get('archive_db_username'));
    }

    public function testConnection(): void
    {
        $check = app(ArchiveConnectionValidator::class)->check($this->formCredentials());

        Flux::toast(variant: $check->ok ? 'success' : 'danger', text: $check->message);

        $this->reset('connPassword');
    }

    public function saveConnection(): void
    {
        $this->validate([
            'connHost' => 'required|string|max:255',
            'connPort' => 'required|integer|min:1|max:65535',
            'connDatabase' => 'required|string|max:255',
            'connUsername' => 'required|string|max:255',
            'connPassword' => 'nullable|string|max:255',
        ]);

        $check = app(ArchiveConnectionValidator::class)->check($this->formCredentials());

        if (! $check->ok) {
            Flux::toast(variant: 'danger', text: $check->message);

            return;
        }

        Setting::set('archive_db_host', trim($this->connHost));
        Setting::set('archive_db_port', (string) ((int) $this->connPort), 'integer');
        Setting::set('archive_db_database', trim($this->connDatabase));
        Setting::set('archive_db_username', trim($this->connUsername));

        if ($this->connPassword !== '') {
            Setting::set('archive_db_password', Crypt::encryptString($this->connPassword));
        }

        Setting::flushCache();

        $this->reset('connPassword');

        $this->editingConnection = false;

        Flux::toast(variant: 'success', text: $check->message);
    }

    public function editConnection(): void
    {
        $this->editingConnection = true;
    }

    public function clearConnection(): void
    {
        Setting::query()->whereIn('key', [
            'archive_db_host',
            'archive_db_port',
            'archive_db_database',
            'archive_db_username',
            'archive_db_password',
        ])->delete();

        Setting::flushCache();

        $this->reset('connHost', 'connPort', 'connDatabase', 'connUsername', 'connPassword');
        $this->connPort = '3306';

        $this->editingConnection = true;

        Flux::modal('confirm-clear-connection')->close();

        Flux::toast(variant: 'success', text: __('Archive database credentials cleared.'));
    }

    public function startArchive(): void
    {
        if ($this->anyRunActive()) {
            Flux::toast(variant: 'danger', text: __('An archive or cleanup run is already in progress.'));

            return;
        }

        if (! $this->connectionConfigured()) {
            Flux::toast(variant: 'danger', text: __('Configure and save the archive database connection first.'));

            return;
        }

        $this->validate([
            'selectedGroups' => 'required|array|min:1',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom|before:today',
        ], [], ['selectedGroups' => 'tables']);

        $run = ArchiveRun::create([
            'status' => ArchiveRunStatus::Pending,
            'mode' => ArchiveRunMode::Archive,
            'created_by' => auth()->id(),
            'options' => [
                'selected_groups' => $this->selectedGroups,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ],
            'archive_credentials' => $this->storedCredentials(),
        ]);

        RunArchiveJob::dispatch($run->id);

        $this->activeRunId = $run->id;

        Flux::toast(variant: 'success', text: __('Archive started — you can leave this page and come back.'));
    }

    public function confirmCleanup(int $sourceId): void
    {
        $this->cleanupSourceId = $sourceId;
    }

    public function startCleanup(): void
    {
        if ($this->anyRunActive()) {
            Flux::toast(variant: 'danger', text: __('An archive or cleanup run is already in progress.'));

            return;
        }

        $source = ArchiveRun::find($this->cleanupSourceId);

        if (! $source || $source->status !== ArchiveRunStatus::Completed || $source->mode !== ArchiveRunMode::Archive) {
            Flux::toast(variant: 'danger', text: __('That archive run is no longer available.'));

            Flux::modal('confirm-cleanup')->close();

            return;
        }

        $run = ArchiveRun::create([
            'status' => ArchiveRunStatus::Pending,
            'mode' => ArchiveRunMode::Cleanup,
            'created_by' => auth()->id(),
            'options' => [
                'source_archive_run_id' => $source->id,
                'selected_groups' => $source->options['selected_groups'] ?? [],
                'date_from' => $source->options['date_from'] ?? null,
                'date_to' => $source->options['date_to'] ?? null,
            ],
            'archive_credentials' => $this->storedCredentials(),
        ]);

        RunArchiveCleanupJob::dispatch($run->id);

        $this->activeRunId = $run->id;
        $this->cleanupSourceId = null;

        Flux::modal('confirm-cleanup')->close();

        Flux::toast(variant: 'success', text: __('Cleanup started — records will be removed from the main database in the background.'));
    }

    public function cancelRun(): void
    {
        if (! $this->activeRunId) {
            return;
        }

        $run = ArchiveRun::find($this->activeRunId);

        if (! $run || ! in_array($run->status, [ArchiveRunStatus::Pending, ArchiveRunStatus::Running], true)) {
            return;
        }

        $run->update(['cancelled_at' => now()]);

        Flux::modal('confirm-cancel')->close();

        Flux::toast(variant: 'success', text: __('Cancellation requested — the run will stop shortly, after its current batch finishes.'));
    }

    public function with(): array
    {
        $activeRun = $this->activeRunId
            ? ArchiveRun::with('tables')->find($this->activeRunId)
            : null;

        $runInProgress = $activeRun && in_array($activeRun->status, [ArchiveRunStatus::Pending, ArchiveRunStatus::Running], true);

        return [
            'activeRun' => $activeRun,
            'runInProgress' => $runInProgress,
            'connectionConfigured' => $this->connectionConfigured(),
            'storedConnection' => [
                'host' => Setting::get('archive_db_host'),
                'port' => Setting::get('archive_db_port'),
                'database' => Setting::get('archive_db_database'),
                'username' => Setting::get('archive_db_username'),
                'hasPassword' => filled(Setting::get('archive_db_password')),
            ],
            'completedArchives' => ArchiveRun::where('mode', ArchiveRunMode::Archive)
                ->where('status', ArchiveRunStatus::Completed)
                ->latest('id')
                ->take(20)
                ->get(),
            'groups' => app(ArchiveTableRegistry::class)->selectableGroups(),
        ];
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Data Archive"
        subtitle="Copy older records into a separate database, then clear them from the main database to keep it fast."
    />

    {{-- Connection --}}
    <div class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Archive Database Connection</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    A separate MySQL database that older records are copied into. Credentials are stored encrypted; the archive schema is created automatically on the first run.
                </p>
            </div>
            @if ($connectionConfigured && ! $editingConnection)
                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <flux:badge color="green" icon="check-circle">Active</flux:badge>
                    </div>
                    <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Host</dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $storedConnection['host'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Port</dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $storedConnection['port'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Database</dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $storedConnection['database'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Username</dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $storedConnection['username'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Password</dt>
                            <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $storedConnection['hasPassword'] ? '••••••••' : '—' }}</dd>
                        </div>
                    </dl>
                </div>
            @else
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <flux:input wire:model="connHost" :label="__('Host')" placeholder="e.g. 203.0.113.10" />
                        <flux:input wire:model="connPort" type="number" :label="__('Port')" placeholder="3306" />
                        <flux:input wire:model="connDatabase" :label="__('Database')" />
                        <flux:input wire:model="connUsername" :label="__('Username')" />
                    </div>
                    <flux:input wire:model="connPassword" type="password" :label="__('Password')" viewable :description="__('Leave blank to keep the stored password.')" />
                </div>
            @endif
        </div>

        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            @if ($connectionConfigured && ! $editingConnection)
                <flux:button variant="ghost" wire:click="testConnection">Test Connection</flux:button>
                <flux:button variant="ghost" wire:click="editConnection">Update credentials</flux:button>
                <flux:button variant="danger" x-on:click="$flux.modal('confirm-clear-connection').show()">Clear credentials</flux:button>
            @else
                @if ($connectionConfigured)
                    <flux:button variant="ghost" wire:click="$set('editingConnection', false)">Cancel</flux:button>
                @endif
                <flux:button variant="ghost" wire:click="testConnection">Test Connection</flux:button>
                <flux:button variant="primary" wire:click="saveConnection">Save Connection</flux:button>
            @endif
        </div>
    </div>

    @if ($runInProgress)
        <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 text-sm text-zinc-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-400">
            A run is currently in progress — the archive and cleanup forms are hidden until it finishes to prevent starting a second one.
        </div>
    @endif

    @unless ($runInProgress)
        {{-- Archive --}}
        <div class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
            <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Archive Older Records</h2>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Copies matching rows into the archive database. Rows are selected by their creation date. Nothing is deleted from the main database at this step.
                    </p>
                </div>
                <div class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="dateFrom" type="date" :label="__('From')" />
                        <flux:input wire:model="dateTo" type="date" :label="__('To')" />
                    </div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Only records created before today can be archived.</p>

                    <div class="space-y-3">
                        <label class="flex items-center gap-2 border-b border-zinc-200/70 pb-3 dark:border-white/10">
                            <flux:checkbox wire:model.live="selectAll" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Select all</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($groups as $key => $meta)
                                <label class="flex items-center gap-2">
                                    <flux:checkbox wire:model="selectedGroups" value="{{ $key }}" />
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $meta['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('selectedGroups') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        @error('dateFrom') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        @error('dateTo') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
                <flux:button variant="primary" wire:click="startArchive">Start Archive</flux:button>
            </div>
        </div>

        {{-- Cleanup --}}
        <div class="flex flex-col gap-4 rounded-2xl border border-zinc-200/70 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Clean Up the Main Database</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Cleanup permanently deletes from the main database the rows an archive run already copied. It runs only against a completed archive.
                </p>
            </div>

            <div class="divide-y divide-zinc-200/70 dark:divide-white/10">
                @forelse ($completedArchives as $archive)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $archive->options['date_from'] ?? '—' }} &ndash; {{ $archive->options['date_to'] ?? '—' }}
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                @php
                                    $labels = collect($archive->options['selected_groups'] ?? [])
                                        ->map(fn ($key) => $groups[$key]['label'] ?? $key)
                                        ->implode(', ');
                                @endphp
                                {{ $labels ?: '—' }}
                            </p>
                            @if ($archive->finished_at)
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Archived {{ $archive->finished_at->diffForHumans() }}</p>
                            @endif
                        </div>
                        <flux:button
                            size="sm"
                            variant="danger"
                            wire:click="confirmCleanup({{ $archive->id }})"
                            x-on:click="$flux.modal('confirm-cleanup').show()"
                        >
                            Run Cleanup
                        </flux:button>
                    </div>
                @empty
                    <p class="py-3 text-sm text-zinc-500 dark:text-zinc-400">No completed archive runs yet.</p>
                @endforelse
            </div>
        </div>
    @endunless

    @if ($activeRun)
        @php
            $overallTotal = $activeRun->tables->sum('rows_total');
            $overallProcessed = $activeRun->tables->sum(fn ($t) => $t->rows_copied + $t->rows_deleted + $t->rows_skipped + $t->rows_failed);
            $overallPercent = $overallTotal > 0 ? min(100, (int) round($overallProcessed / $overallTotal * 100)) : 0;
            $isArchive = $activeRun->mode === ArchiveRunMode::Archive;
        @endphp
        <div
            class="flex flex-col gap-4 rounded-2xl border border-zinc-200/70 bg-white p-4 dark:border-white/10 dark:bg-zinc-900"
            @if (in_array($activeRun->status, [ArchiveRunStatus::Pending, ArchiveRunStatus::Running], true))
                wire:poll.2s="$refresh"
            @endif
        >
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $activeRun->mode->label() }} Progress</h2>
                <div class="flex items-center gap-2">
                    <flux:badge
                        :color="match ($activeRun->status) {
                            ArchiveRunStatus::Pending => 'zinc',
                            ArchiveRunStatus::Running => 'blue',
                            ArchiveRunStatus::Completed => 'green',
                            ArchiveRunStatus::Failed => 'red',
                            ArchiveRunStatus::Cancelled => 'zinc',
                        }"
                    >
                        {{ $activeRun->cancelled_at && $activeRun->status === ArchiveRunStatus::Running ? 'Cancelling…' : $activeRun->status->label() }}
                    </flux:badge>

                    @if ($runInProgress && ! $activeRun->cancelled_at)
                        <flux:button size="sm" variant="danger" x-on:click="$flux.modal('confirm-cancel').show()">
                            Cancel
                        </flux:button>
                    @endif
                </div>
            </div>

            @if ($activeRun->status === ArchiveRunStatus::Failed && $activeRun->error)
                <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">
                    {{ $activeRun->error }}
                </div>
            @endif

            @if ($activeRun->status === ArchiveRunStatus::Pending && ! $activeRun->started_at && $activeRun->created_at->diffInSeconds(now()) > 15)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Still waiting for a queue worker to pick this up</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        The job has been queued for over 15 seconds with nothing processing it. On this environment there's no persistent worker — either wait for the next scheduled tick, or run this now:
                    </p>
                    <code class="mt-2 block rounded-lg bg-amber-100 px-3 py-2 font-mono text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">php artisan queue:work --queue=archives --stop-when-empty</code>
                </div>
            @endif

            @if ($activeRun->status === ArchiveRunStatus::Completed)
                @php
                    $totals = $activeRun->tables->reduce(fn ($carry, $table) => [
                        'copied' => $carry['copied'] + $table->rows_copied,
                        'deleted' => $carry['deleted'] + $table->rows_deleted,
                        'skipped' => $carry['skipped'] + $table->rows_skipped,
                        'failed' => $carry['failed'] + $table->rows_failed,
                    ], ['copied' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0]);
                    $duration = $activeRun->started_at && $activeRun->finished_at
                        ? $activeRun->started_at->diffForHumans($activeRun->finished_at, true)
                        : null;
                @endphp
                <div class="rounded-xl border border-green-300 bg-green-50 p-4 dark:border-green-500/30 dark:bg-green-500/10">
                    <p class="text-sm font-semibold text-green-800 dark:text-green-300">{{ $activeRun->mode->label() }} complete{{ $duration ? " — took {$duration}" : '' }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @if ($isArchive)
                            <div><p class="text-xs text-green-700 dark:text-green-400">Copied</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['copied'] }}</p></div>
                        @else
                            <div><p class="text-xs text-green-700 dark:text-green-400">Deleted</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['deleted'] }}</p></div>
                            <div><p class="text-xs text-green-700 dark:text-green-400">Kept</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['skipped'] }}</p></div>
                        @endif
                        <div><p class="text-xs text-green-700 dark:text-green-400">Failed</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['failed'] }}</p></div>
                    </div>
                </div>
            @endif

            @if ($activeRun->tables->isNotEmpty())
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-semibold text-zinc-900 dark:text-white">Overall progress</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $overallProcessed }} / {{ $overallTotal }} rows ({{ $overallPercent }}%)</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ $overallPercent }}%"></div>
                    </div>
                </div>
            @endif

            <div class="space-y-3">
                @forelse ($activeRun->tables as $table)
                    @php
                        $processed = $table->rows_copied + $table->rows_deleted + $table->rows_skipped + $table->rows_failed;
                        $percent = $table->rows_total > 0 ? min(100, (int) round($processed / $table->rows_total * 100)) : 0;
                    @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $table->entity }}
                                <flux:badge size="sm" :color="match ($table->status) {
                                    ArchiveRunStatus::Pending => 'zinc',
                                    ArchiveRunStatus::Running => 'blue',
                                    ArchiveRunStatus::Completed => 'green',
                                    ArchiveRunStatus::Failed => 'red',
                                    ArchiveRunStatus::Cancelled => 'zinc',
                                }">{{ $table->status->label() }}</flux:badge>
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($isArchive)
                                    Copied: {{ $table->rows_copied }} &middot; Skipped: {{ $table->rows_skipped }} &middot; Failed: {{ $table->rows_failed }}
                                @else
                                    Deleted: {{ $table->rows_deleted }} &middot; Kept: {{ $table->rows_skipped }} &middot; Failed: {{ $table->rows_failed }}
                                @endif
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ $percent }}%"></div>
                        </div>
                        @if ($table->error)
                            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $table->error }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Waiting for the job to start processing…</p>
                @endforelse
            </div>
        </div>
    @endif

    <flux:modal name="confirm-cleanup" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Run Cleanup?</flux:heading>
                <flux:subheading>
                    Permanently delete the archived rows from the main database? Rows still referenced by live records are kept and reported. This cannot be undone.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="startCleanup" wire:loading.attr="disabled">
                    Run Cleanup
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-clear-connection" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Clear archive credentials?</flux:heading>
                <flux:subheading>
                    The stored archive database credentials will be permanently removed. Archive and cleanup runs will not work until new credentials are saved. Existing archived data is not affected.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="clearConnection" wire:loading.attr="disabled">
                    Clear credentials
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-cancel" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Cancel Run?</flux:heading>
                <flux:subheading>
                    Already-completed entities are kept as-is. The entity currently running stops once its in-progress batch finishes — this may take a moment, not instantly.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">Keep Running</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="cancelRun" wire:loading.attr="disabled">
                    Cancel Run
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
