<?php

use App\Jobs\RunLegacyMigrationJob;
use App\MigrationRunStatus;
use App\Models\MigrationRun;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use App\UserRole;
use App\UserStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Legacy Data Migration')] class extends Component {
    /** @var array<int, string> */
    public array $selectedGroups = [];

    public bool $selectAll = false;

    public string $duplicateStrategy = 'update_existing';

    public string $clearMode = 'none';

    public ?int $runAsUserId = null;

    public ?int $activeRunId = null;

    public string $legacyHost = '';

    public string $legacyPort = '1433';

    public string $legacyDatabase = '';

    public string $legacyUsername = '';

    public string $legacyPassword = '';

    public const array GROUPS = [
        'customers' => 'Customers',
        'suppliers' => 'Suppliers',
        'delivery_documents' => 'Delivery Notes, Invoices & Credit Notes',
        'purchase_invoices' => 'Purchase Invoices',
        'purchase_credit_notes' => 'Purchase Credit Notes',
    ];

    public function mount(): void
    {
        $currentUser = auth()->user();
        $this->runAsUserId = $currentUser?->isAdmin()
            ? $currentUser->id
            : User::where('role', UserRole::Admin)->value('id');

        // Pre-fill from .env as a convenient default (e.g. for dev/staging), but every field
        // here is editable per-run since production credentials are typically different and
        // may not be known at deploy time — see start()/applyEnteredCredentials() below.
        $legacyConfig = config('database.connections.legacy', []);
        $this->legacyHost = (string) ($legacyConfig['host'] ?? '');
        $this->legacyPort = (string) ($legacyConfig['port'] ?? '1433');
        $this->legacyDatabase = (string) ($legacyConfig['database'] ?? '');
        $this->legacyUsername = (string) ($legacyConfig['username'] ?? '');
        // Password is intentionally never pre-filled from .env — must be re-entered per run.

        $this->activeRunId = MigrationRun::latest('id')->value('id');
    }

    public function updatedSelectAll(): void
    {
        $this->selectedGroups = $this->selectAll ? array_keys(self::GROUPS) : [];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function enteredCredentials(): array
    {
        return [
            'host' => $this->legacyHost,
            'port' => (int) ($this->legacyPort ?: 1433),
            'database' => $this->legacyDatabase,
            'username' => $this->legacyUsername,
            'password' => $this->legacyPassword,
        ];
    }

    /**
     * Applies the currently entered form values to the 'legacy' connection for this request only —
     * never persisted here. Used by both testConnection() and (indirectly, via the stored encrypted
     * value read by the job) the real migration run.
     */
    private function applyEnteredCredentialsForThisRequest(): void
    {
        config(['database.connections.legacy' => array_merge(
            config('database.connections.legacy', []),
            $this->enteredCredentials(),
        )]);

        DB::purge('legacy');
    }

    public function testConnection(): void
    {
        if ($this->legacyHost === '' || $this->legacyDatabase === '' || $this->legacyUsername === '') {
            Flux::toast(variant: 'danger', text: __('Enter at least host, database and username before testing.'));

            return;
        }

        try {
            $this->applyEnteredCredentialsForThisRequest();

            DB::connection('legacy')->getPdo();

            Flux::toast(variant: 'success', text: __('Connected to legacy database.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Connection failed: :message', ['message' => str($e->getMessage())->limit(150)]));
        }
    }

    public function start(): void
    {
        if (empty($this->selectedGroups)) {
            Flux::toast(variant: 'danger', text: __('Select at least one entity to migrate.'));

            return;
        }

        if (! $this->runAsUserId || ! User::where('id', $this->runAsUserId)->where('role', UserRole::Admin)->exists()) {
            Flux::toast(variant: 'danger', text: __('Select a valid admin user to run the migration as.'));

            return;
        }

        if ($this->legacyHost === '' || $this->legacyDatabase === '' || $this->legacyUsername === '') {
            Flux::toast(variant: 'danger', text: __('Enter the legacy database host, database and username before starting.'));

            return;
        }

        $credentials = $this->enteredCredentials();

        $run = MigrationRun::create([
            'status' => MigrationRunStatus::Pending,
            'created_by' => auth()->id(),
            'options' => [
                'selected_groups' => $this->selectedGroups,
                'duplicate_strategy' => $this->duplicateStrategy,
                'clear_mode' => $this->clearMode,
                'run_as_user_id' => $this->runAsUserId,
                // Credentials NEVER go here — see legacy_credentials (encrypted column) below.
            ],
            // Encrypted at rest via the model's cast; cleared automatically once the job finishes.
            'legacy_credentials' => $credentials,
        ]);

        RunLegacyMigrationJob::dispatch(
            $run->id,
            $this->selectedGroups,
            $this->duplicateStrategy,
            $this->clearMode,
            $this->runAsUserId,
        );

        // Drop the password out of this component's state now that it's safely persisted
        // (encrypted) on the run — no reason for it to keep riding along in Livewire's snapshot.
        $this->reset('legacyPassword');

        $this->activeRunId = $run->id;

        Flux::modal('confirm-full-wipe')->close();

        Flux::toast(variant: 'success', text: __('Migration started — this may take a while; you can leave this page and come back.'));
    }

    public function dismissFallbackAdminNotice(): void
    {
        if (! $this->activeRunId) {
            return;
        }

        $run = MigrationRun::find($this->activeRunId);

        if (! $run || ! isset($run->options['fallback_admin'])) {
            return;
        }

        $options = $run->options;
        unset($options['fallback_admin']);
        $run->update(['options' => $options]);
    }

    public function cancelRun(): void
    {
        if (! $this->activeRunId) {
            return;
        }

        $run = MigrationRun::find($this->activeRunId);

        if (! $run || ! in_array($run->status, [MigrationRunStatus::Pending, MigrationRunStatus::Running], true)) {
            return;
        }

        $run->update(['cancelled_at' => now()]);

        Flux::modal('confirm-cancel-migration')->close();

        Flux::toast(variant: 'success', text: __('Cancellation requested — the migration will stop shortly, after its current batch finishes.'));
    }

    public function with(): array
    {
        $activeRun = $this->activeRunId
            ? MigrationRun::with('tables')->find($this->activeRunId)
            : null;

        $runInProgress = $activeRun && in_array($activeRun->status, [MigrationRunStatus::Pending, MigrationRunStatus::Running], true);

        return [
            'activeRun' => $activeRun,
            'runInProgress' => $runInProgress,
            'adminUsers' => User::where('role', UserRole::Admin)->orderBy('name')->get(),
            'migratedUserCount' => User::where('status', UserStatus::Migrated)->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Legacy Data Migration"
        subtitle="Import customers, suppliers and documents from the legacy XRFasteners system."
    />

    @if ($runInProgress)
        <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 text-sm text-zinc-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-400">
            A migration is currently running — the form is hidden until it finishes to prevent starting a second one.
        </div>
    @endif

    @unless ($runInProgress)
    <div class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        {{-- Legacy database connection --}}
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Legacy Database Connection</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pre-filled from this environment's configuration, but editable — production credentials are typically different and can be entered fresh here for each run. Nothing here is stored in plain text.</p>
            </div>
            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <flux:input wire:model="legacyHost" :label="__('Host')" placeholder="e.g. 203.0.113.10" />
                    <flux:input wire:model="legacyPort" type="number" :label="__('Port')" placeholder="1433" />
                    <flux:input wire:model="legacyDatabase" :label="__('Database')" />
                    <flux:input wire:model="legacyUsername" :label="__('Username')" />
                </div>
                <flux:input wire:model="legacyPassword" type="password" :label="__('Password')" viewable />
            </div>
        </div>

        {{-- Entities --}}
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Entities to Migrate</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Choose which record types to pull from the legacy database.</p>
            </div>
            <div class="space-y-3">
                <label class="flex items-center gap-2 border-b border-zinc-200/70 pb-3 dark:border-white/10">
                    <flux:checkbox wire:model.live="selectAll" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Select all</span>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (self::GROUPS as $key => $label)
                        <label class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedGroups" value="{{ $key }}" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Duplicate strategy --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Duplicate Handling</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">How to treat records that already exist locally.</p>
            </div>
            <flux:radio.group wire:model="duplicateStrategy" class="flex flex-col gap-2">
                <flux:radio value="update_existing" label="Update existing records" />
                <flux:radio value="skip_existing" label="Skip existing records" />
            </flux:radio.group>
        </div>

        {{-- Clear mode --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Clear Data Before Import</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Optionally wipe existing data before importing.</p>
            </div>
            <flux:radio.group wire:model.live="clearMode" class="flex flex-col gap-2">
                <flux:radio value="none" label="Don't clear anything (recommended for repeat runs)" />
                <flux:radio value="migrated_only" label="Clear previously migrated data only" />
                <label class="flex items-center gap-2">
                    <flux:radio value="full_wipe" />
                    <span class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
                        <flux:icon.exclamation-triangle class="size-4 shrink-0" />
                        Full wipe of selected tables (also deletes manually entered data — use with caution)
                    </span>
                </label>
            </flux:radio.group>
        </div>

        {{-- Run as --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Run As</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Admin user attributed as the author of migrated records.</p>
            </div>
            <flux:select wire:model="runAsUserId" :label="__('Admin User')" class="max-w-xs">
                @foreach ($adminUsers as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Actions --}}
        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <flux:button variant="ghost" wire:click="testConnection">Test Connection</flux:button>
            <flux:button
                variant="primary"
                x-on:click="{{ $this->clearMode === 'full_wipe' ? '$flux.modal(\'confirm-full-wipe\').show()' : '$wire.start()' }}"
            >
                Start Migration
            </flux:button>
        </div>
    </div>
    @endunless

    @if ($activeRun)
        <div
            class="flex flex-col gap-4 rounded-2xl border border-zinc-200/70 bg-white p-4 dark:border-white/10 dark:bg-zinc-900"
            @if (in_array($activeRun->status, [MigrationRunStatus::Pending, MigrationRunStatus::Running], true))
                wire:poll.2s="$refresh"
            @endif
        >
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Migration Progress</h2>
                <div class="flex items-center gap-2">
                    <flux:badge
                        :color="match ($activeRun->status) {
                            MigrationRunStatus::Pending => 'zinc',
                            MigrationRunStatus::Running => 'blue',
                            MigrationRunStatus::Completed => 'green',
                            MigrationRunStatus::Failed => 'red',
                            MigrationRunStatus::Cancelled => 'zinc',
                        }"
                    >
                        {{ $activeRun->cancelled_at && $activeRun->status === MigrationRunStatus::Running ? 'Cancelling…' : $activeRun->status->label() }}
                    </flux:badge>

                    @if ($runInProgress && ! $activeRun->cancelled_at)
                        <flux:button size="sm" variant="danger" x-on:click="$flux.modal('confirm-cancel-migration').show()">
                            Cancel
                        </flux:button>
                    @endif
                </div>
            </div>

            @if (isset($activeRun->options['fallback_admin']))
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">A fallback admin account was created for this run</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        Email: <span class="font-mono">{{ $activeRun->options['fallback_admin']['email'] ?? '—' }}</span>
                        &middot;
                        Password: <span class="font-mono">{{ $activeRun->options['fallback_admin']['password'] ?? '—' }}</span>
                    </p>
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">Save this now and change the password immediately — it will not be shown again.</p>
                    <flux:button size="sm" class="mt-3" wire:click="dismissFallbackAdminNotice">I've saved this</flux:button>
                </div>
            @endif

            @if ($activeRun->status === MigrationRunStatus::Failed && $activeRun->error)
                <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">
                    {{ $activeRun->error }}
                </div>
            @endif

            @if ($activeRun->status === MigrationRunStatus::Pending && ! $activeRun->started_at && $activeRun->created_at->diffInSeconds(now()) > 15)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Still waiting for a queue worker to pick this up</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        The job has been queued for over 15 seconds with nothing processing it. On this environment there's no persistent worker — either wait for the next scheduled tick, or run this now:
                    </p>
                    <code class="mt-2 block rounded-lg bg-amber-100 px-3 py-2 font-mono text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">php artisan queue:work --queue=migrations --stop-when-empty</code>
                </div>
            @endif

            @if ($activeRun->status === MigrationRunStatus::Completed)
                @php
                    $totals = $activeRun->tables->reduce(fn ($carry, $table) => [
                        'added' => $carry['added'] + $table->added,
                        'updated' => $carry['updated'] + $table->updated,
                        'skipped' => $carry['skipped'] + $table->skipped,
                        'failed' => $carry['failed'] + $table->failed,
                    ], ['added' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0]);
                    $duration = $activeRun->started_at && $activeRun->finished_at
                        ? $activeRun->started_at->diffForHumans($activeRun->finished_at, true)
                        : null;
                @endphp
                <div class="rounded-xl border border-green-300 bg-green-50 p-4 dark:border-green-500/30 dark:bg-green-500/10">
                    <p class="text-sm font-semibold text-green-800 dark:text-green-300">Migration complete{{ $duration ? " — took {$duration}" : '' }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div><p class="text-xs text-green-700 dark:text-green-400">Added</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['added'] }}</p></div>
                        <div><p class="text-xs text-green-700 dark:text-green-400">Updated</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['updated'] }}</p></div>
                        <div><p class="text-xs text-green-700 dark:text-green-400">Skipped</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['skipped'] }}</p></div>
                        <div><p class="text-xs text-green-700 dark:text-green-400">Failed</p><p class="text-lg font-semibold text-green-900 dark:text-green-200">{{ $totals['failed'] }}</p></div>
                    </div>
                </div>
            @endif

            @if ($activeRun->tables->isNotEmpty())
                @php
                    $overallTotal = $activeRun->tables->sum('rows_total');
                    $overallProcessed = $activeRun->tables->sum('rows_processed');
                    $overallPercent = $overallTotal > 0 ? min(100, (int) round($overallProcessed / $overallTotal * 100)) : 0;
                @endphp
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
                        $percent = $table->rows_total > 0 ? min(100, (int) round($table->rows_processed / $table->rows_total * 100)) : 0;
                    @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $table->entity }}
                                <flux:badge size="sm" :color="match ($table->status) {
                                    MigrationRunStatus::Pending => 'zinc',
                                    MigrationRunStatus::Running => 'blue',
                                    MigrationRunStatus::Completed => 'green',
                                    MigrationRunStatus::Failed => 'red',
                                    MigrationRunStatus::Cancelled => 'zinc',
                                }">{{ $table->status->label() }}</flux:badge>
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                Added: {{ $table->added }} &middot; Updated: {{ $table->updated }} &middot; Skipped: {{ $table->skipped }} &middot; Failed: {{ $table->failed }}
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                class="h-full rounded-full bg-indigo-600 transition-all"
                                style="width: {{ $percent }}%"
                            ></div>
                        </div>
                        @if ($table->orphaned_in_legacy > 0)
                            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                {{ number_format($table->orphaned_in_legacy) }} not migrated — reference a customer/parent that doesn't exist anywhere in the legacy data (not counted above; never fetched)
                            </p>
                        @endif
                        @if ($table->entity === 'documents' && $migratedUserCount > 0)
                            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                {{ number_format($migratedUserCount) }} {{ Str::plural('user', $migratedUserCount) }} added with status: Migrated.
                                <a href="{{ route('users.index') }}" wire:navigate class="underline">View</a>.
                            </p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Waiting for the migration job to start processing…</p>
                @endforelse
            </div>
        </div>
    @endif

    <flux:modal name="confirm-full-wipe" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirm Full Wipe</flux:heading>
                <flux:subheading>
                    This will permanently delete existing data in the selected tables, including manually entered records, before importing from the legacy system. This action cannot be undone.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">Cancel</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="start"
                    wire:loading.attr="disabled"
                >
                    Wipe & Start Migration
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-cancel-migration" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Cancel Migration?</flux:heading>
                <flux:subheading>
                    Already-completed entities are kept as-is. The entity currently running stops once its in-progress batch finishes — this may take a moment, not instantly.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">Keep Running</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="cancelRun"
                    wire:loading.attr="disabled"
                >
                    Cancel Migration
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
