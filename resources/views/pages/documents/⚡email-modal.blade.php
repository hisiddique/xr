<?php

use App\Models\Document;
use App\Services\DocumentEmailService;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Document $document;

    /** @var array<int, string> */
    public array $emails = [];

    public string $notes = '';

    public function mount(): void
    {
        $email = $this->document->customer->email_1 ?? '';
        if ($email) {
            $this->emails = [$email];
        }
    }

    public function send(): void
    {
        $this->validate([
            'emails' => 'required|array|min:1',
            'emails.*' => 'email|max:254',
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            app(DocumentEmailService::class)->send(
                $this->document,
                $this->emails,
                $this->notes ?: null,
            );

            $this->dispatch('email-log-updated');
            Flux::modal('email-document-'.$this->document->id)->close();

            Flux::toast(variant: 'success', text: __('Email sent to :recipient.', ['recipient' => $this->emails[0]]));
        } catch (\Throwable $e) {
            $this->addError('emails', $e->getMessage());
        }
    }
}; ?>

<div>
    <flux:modal name="email-document-{{ $document->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send :type', ['type' => $document->type->label()]) }}</flux:heading>
                <flux:subheading>
                    {{ __('Send :number to the customer by email.', ['number' => $document->doc_number]) }}
                </flux:subheading>
            </div>

            <form wire:submit="send" class="space-y-4">
                <div x-data="emailTagInput($wire, @js($emails))">
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
                    <p class="mt-1 text-xs text-zinc-400">Press <kbd class="rounded border border-zinc-200 bg-zinc-100 px-1 dark:border-zinc-700 dark:bg-zinc-800">Enter</kbd> or <kbd class="rounded border border-zinc-200 bg-zinc-100 px-1 dark:border-zinc-700 dark:bg-zinc-800">,</kbd> to add each address.</p>
                    <flux:error name="emails" />
                    <flux:error name="emails.*" />
                </div>

                <div>
                    <flux:label>{{ __('Additional Notes') }}</flux:label>
                    <flux:textarea
                        wire:model="notes"
                        :placeholder="__('Optional message to include in the email…')"
                        rows="3"
                    />
                    <flux:error name="notes" />
                </div>

                <div class="flex justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button
                        variant="primary"
                        icon="envelope"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="send"
                    >
                        <span wire:loading.remove wire:target="send">{{ __('Send Email') }}</span>
                        <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
