<?php

use App\Models\Document;
use App\Services\DocumentEmailService;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Document $document;

    public string $recipient = '';

    public function mount(): void
    {
        $this->recipient = $this->document->customer->email_1 ?? '';
    }

    public function send(): void
    {
        $this->validate([
            'recipient' => 'required|email|max:254',
        ]);

        try {
            app(DocumentEmailService::class)->send($this->document, $this->recipient);

            $this->dispatch('email-log-updated');
            Flux::modal('email-document-'.$this->document->id)->close();

            Flux::toast(variant: 'success', text: __('Email sent to :recipient.', ['recipient' => $this->recipient]));
        } catch (\Throwable $e) {
            $this->addError('recipient', $e->getMessage());
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
                <div>
                    <flux:label>{{ __('Recipient Email') }} <span class="text-rose-500">*</span></flux:label>
                    <flux:input
                        wire:model="recipient"
                        type="email"
                        :placeholder="__('customer@example.com')"
                        autofocus
                    />
                    <flux:error name="recipient" />
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
