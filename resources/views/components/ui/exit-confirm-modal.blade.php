<flux:modal name="exit-confirm" class="md:max-w-md">
    <div x-data="exitConfirmNav" class="space-y-5">
        <div>
            <flux:heading size="lg">Discard changes?</flux:heading>
            <flux:text class="mt-1">You have unsaved changes on this form. What would you like to do?</flux:text>
        </div>
        <div
            x-ref="buttons"
            x-on:keydown.right.prevent="move(1)"
            x-on:keydown.left.prevent="move(-1)"
            x-on:keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
            class="flex justify-end gap-2 [&_button:focus]:ring-2 [&_button:focus]:ring-indigo-500 [&_button:focus]:ring-offset-2 [&_button:focus]:outline-none dark:[&_button:focus]:ring-offset-zinc-900"
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
