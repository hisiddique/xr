<flux:modal name="clone-confirm" class="md:max-w-md">
    <div x-data="cloneConfirm" x-on:clone-confirm.window="open($event.detail)" class="space-y-5">
        <div>
            <flux:heading size="lg" x-text="'Clone ' + label + '?'"></flux:heading>
            <flux:text class="mt-1" x-text="'A new ' + label + ' will be created as a copy of ' + number + '.'"></flux:text>
        </div>
        <form method="POST" x-bind:action="url" class="flex justify-end gap-2">
            @csrf
            <flux:modal.close>
                <flux:button type="button" variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">Clone</flux:button>
        </form>
    </div>
</flux:modal>
