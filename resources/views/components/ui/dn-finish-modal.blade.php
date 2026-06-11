<flux:modal name="dn-finish" class="md:max-w-md">
    <div x-data="dnFinishNav" class="space-y-5" x-on:keydown="handleShortcut($event)">

        {{-- Step 1: what to do with the entry --}}
        <div x-show="step === 1" class="space-y-5">
            <flux:heading size="lg">Do you wish to?</flux:heading>
            <div
                x-ref="step1"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
                class="flex flex-col gap-2 [&_button:focus]:ring-2 [&_button:focus]:ring-indigo-500 [&_button:focus]:ring-offset-2 [&_button:focus]:outline-none dark:[&_button:focus]:ring-offset-zinc-900"
            >
                <flux:button class="justify-start" x-on:click="choose('print')"><div><u>P</u>rint document and hold on file</div></flux:button>
                <flux:button class="justify-start" x-on:click="choose('email')"><div><u>E</u>mail document and hold on file</div></flux:button>
                <flux:button class="justify-start" x-on:click="choose('emailprint')"><div>Email <u>a</u>nd print document and hold on file</div></flux:button>
                <flux:button class="justify-start" x-on:click="choose('holdonly')"><div><u>H</u>old on file but do not print</div></flux:button>
                <flux:button variant="danger" class="justify-start" x-on:click="reject()"><div><u>R</u>eject entries</div></flux:button>
            </div>
        </div>

        {{-- Step 2: order-value visibility --}}
        <div x-show="step === 2" x-cloak class="space-y-5">
            <flux:heading size="lg">Order values</flux:heading>
            <flux:text>Show or hide order values on this delivery note?</flux:text>
            <div
                x-ref="step2"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
                class="flex flex-col gap-2 [&_button:focus]:ring-2 [&_button:focus]:ring-indigo-500 [&_button:focus]:ring-offset-2 [&_button:focus]:outline-none dark:[&_button:focus]:ring-offset-zinc-900"
            >
                <flux:button class="justify-start" x-on:click="finish(false)">Hide order values</flux:button>
                <flux:button class="justify-start" x-on:click="finish(true)">Show order values</flux:button>
            </div>
        </div>
    </div>
</flux:modal>
