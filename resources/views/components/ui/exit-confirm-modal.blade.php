<flux:modal name="exit-confirm" class="md:max-w-md" x-on:open="$nextTick(() => $refs.exitConfirmButtons?.querySelector('button')?.focus())">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Discard changes?</flux:heading>
            <flux:text class="mt-1">You have unsaved changes on this form. What would you like to do?</flux:text>
        </div>
        <div
            x-ref="exitConfirmButtons"
            x-on:keydown="
                if ($event.key === 'ArrowRight' || $event.key === 'ArrowLeft') {
                    $event.preventDefault();
                    const btns = Array.from($el.querySelectorAll('button'));
                    const i = btns.indexOf(document.activeElement);
                    const dir = $event.key === 'ArrowRight' ? 1 : -1;
                    const next = btns[(i + dir + btns.length) % btns.length];
                    next?.focus();
                }
            "
            class="flex justify-end gap-2"
        >
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:modal.close>
                <flux:button variant="danger" x-on:click="$dispatch('exit-confirm-discard')">Discard</flux:button>
            </flux:modal.close>
            <flux:modal.close>
                <flux:button variant="primary" x-on:click="$dispatch('exit-confirm-save')">Save changes</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
