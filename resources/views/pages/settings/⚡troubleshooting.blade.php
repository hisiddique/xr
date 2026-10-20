<?php

use Flux\Flux;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Troubleshooting')] class extends Component
{
    public string $recipient = '';

    /** @var array{ok: bool, message: string}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->recipient = (string) auth()->user()->email;
    }

    public function sendTestEmail(): void
    {
        $this->validate(['recipient' => 'required|email|max:255']);

        $appName = (string) config('app.name');

        try {
            Mail::raw(
                'This is a test email from '.$appName.' to confirm your mail configuration works.',
                fn ($message) => $message->to($this->recipient)->subject($appName.' test email'),
            );

            $this->result = [
                'ok' => true,
                'message' => 'Test email sent to '.$this->recipient.' using '.config('mail.default').'.',
            ];

            Flux::toast(variant: 'success', text: __('Test email sent.'));
        } catch (Throwable $e) {
            report($e);

            $this->result = [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function with(): array
    {
        return [
            'defaultMailer' => (string) config('mail.default'),
            'fromAddress' => (string) config('mail.from.address'),
            'fromName' => (string) config('mail.from.name'),
        ];
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Troubleshooting"
        subtitle="Diagnose configuration problems."
    />

    <form wire:submit="sendTestEmail" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Test Email Configuration</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Send a test message to confirm outgoing mail is working. Any error returned by the mail server is shown below.
                </p>
            </div>
            <div class="space-y-5">
                <dl class="grid gap-4 md:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">Default mailer</dt>
                        <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $defaultMailer ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">From address</dt>
                        <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $fromAddress ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">From name</dt>
                        <dd class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $fromName ?: '—' }}</dd>
                    </div>
                </dl>

                <flux:input wire:model="recipient" type="email" :label="__('Send test to')" />

                @if ($result)
                    @if ($result['ok'])
                        <div class="rounded-xl border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">
                            {{ $result['message'] }}
                        </div>
                    @else
                        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">
                            <p class="font-semibold">Sending failed</p>
                            <p class="mt-1 break-words">{{ $result['message'] }}</p>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="sendTestEmail">
                <span wire:loading.remove wire:target="sendTestEmail">Send test email</span>
                <span wire:loading wire:target="sendTestEmail">Sending…</span>
            </flux:button>
        </div>
    </form>

</div>
