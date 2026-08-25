@props(['row', 'payments', 'creditNotes', 'invoices'])

@php
    $statusBadgeClasses = fn (string $status) => match ($status) {
        'applied', 'paid' => 'text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'partial' => 'text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'unapplied', 'overdue' => 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20',
        'overdue_partial' => 'text-orange-600 bg-orange-50 border-orange-200 dark:text-orange-400 dark:bg-orange-500/10 dark:border-orange-500/20',
        'drawn' => 'text-sky-600 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
        default => 'text-zinc-600 bg-zinc-100 border-zinc-200 dark:text-zinc-300 dark:bg-zinc-800 dark:border-zinc-700',
    };
    $statusLabel = fn (string $status) => match ($status) {
        'applied' => 'Applied',
        'partial' => 'Partial',
        'unapplied' => 'Unapplied',
        'drawn' => 'Transferred',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'overdue_partial' => 'Overdue (Partial)',
        'outstanding' => 'Outstanding',
        default => ucfirst($status),
    };
@endphp

@if($row['details']['kind'] === 'invoice')
    <div class="grid gap-4" :class="openLinkedRef ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800/50">
            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Invoice Details: {{ $row['ref_no'] }}</h4>

            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['date']->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Order Ref</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['order_ref'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['total'], 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                    <dd class="font-mono font-medium {{ $row['details']['outstanding'] > 0.005 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        £{{ number_format($row['details']['outstanding'], 2) }}
                    </dd>
                </div>
            </dl>

            <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-white/10">
                @if(empty($row['details']['allocations']))
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">This invoice is currently open/unpaid.</p>
                @else
                    <ul class="space-y-1.5">
                        @foreach($row['details']['allocations'] as $allocation)
                            <li class="flex items-center justify-between gap-2 text-xs">
                                @if($allocation['kind'] === 'payment')
                                    <button
                                        type="button"
                                        class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                        x-on:click="openLinkedRef = openLinkedRef === 'payment:{{ $allocation['payment_id'] }}' ? null : 'payment:{{ $allocation['payment_id'] }}'"
                                    >
                                        {{ $allocation['ref'] }} ({{ $allocation['label'] }})
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                        x-on:click="openLinkedRef = openLinkedRef === 'credit:{{ $allocation['credit_note_id'] }}' ? null : 'credit:{{ $allocation['credit_note_id'] }}'"
                                    >
                                        {{ $allocation['ref'] }} ({{ $allocation['label'] }})
                                    </button>
                                @endif
                                <span class="font-mono text-zinc-600 dark:text-zinc-300">
                                    £{{ number_format($allocation['amount'], 2) }} &middot; {{ $allocation['date']->format('d M Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @foreach($row['details']['allocations'] as $allocation)
            @if($allocation['kind'] === 'payment')
                @php $linkedPayment = $payments[$allocation['payment_id']] ?? null; @endphp
                @if($linkedPayment)
                    <div
                        x-show="openLinkedRef === 'payment:{{ $allocation['payment_id'] }}'"
                        x-cloak
                        class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Payment: {{ $allocation['ref'] }}</h4>
                            <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Method</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedPayment['method'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                                <dd>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linkedPayment['status']) }}">
                                        {{ $statusLabel($linkedPayment['status']) }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedPayment['total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Allocated</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedPayment['allocated'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Unallocated</dt>
                                <dd class="font-mono font-medium {{ $linkedPayment['unallocated'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format($linkedPayment['unallocated'], 2) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif
            @else
                @php $linkedCreditNote = $creditNotes[$allocation['credit_note_id']] ?? null; @endphp
                @if($linkedCreditNote)
                    <div
                        x-show="openLinkedRef === 'credit:{{ $allocation['credit_note_id'] }}'"
                        x-cloak
                        class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Credit Note: {{ $allocation['ref'] }}</h4>
                            <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Date Issued</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedCreditNote['date']->format('d M Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                                <dd>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linkedCreditNote['status']) }}">
                                        {{ $statusLabel($linkedCreditNote['status']) }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedCreditNote['total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Applied Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedCreditNote['applied_total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                                <dd class="font-mono font-medium {{ $linkedCreditNote['outstanding'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format($linkedCreditNote['outstanding'], 2) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif
            @endif
        @endforeach
    </div>
@elseif($row['details']['kind'] === 'credit_note')
    @php
        $relatedInvoiceIds = collect([$row['details']['raised_against_id']])
            ->merge(collect($row['details']['applied_to'])->pluck('invoice_id'))
            ->filter()
            ->unique();
    @endphp
    <div class="grid gap-4" :class="openLinkedRef ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800/50">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs lg:grid-cols-4">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Date Issued</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['date']->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Credit Amount</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['total'], 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($row['status']) }}">
                            {{ $statusLabel($row['status']) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Raised Against</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">
                        @if($row['details']['raised_against'])
                            <button
                                type="button"
                                class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                x-on:click="openLinkedRef = openLinkedRef === 'invoice:{{ $row['details']['raised_against_id'] }}' ? null : 'invoice:{{ $row['details']['raised_against_id'] }}'"
                            >
                                {{ $row['details']['raised_against'] }}
                            </button>
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-white/10">
                @if(empty($row['details']['applied_to']))
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Not yet applied to an invoice.</p>
                @else
                    <p class="mb-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300">Applied to invoices</p>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/[0.06]">
                            @foreach($row['details']['applied_to'] as $applied)
                                <tr>
                                    <td class="py-1">
                                        <button
                                            type="button"
                                            class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                            x-on:click="openLinkedRef = openLinkedRef === 'invoice:{{ $applied['invoice_id'] }}' ? null : 'invoice:{{ $applied['invoice_id'] }}'"
                                        >
                                            {{ $applied['ref'] }}
                                        </button>
                                    </td>
                                    <td class="py-1 text-zinc-500 dark:text-zinc-400">{{ $applied['date']->format('d M Y') }}</td>
                                    <td class="py-1 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($applied['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @foreach($relatedInvoiceIds as $invoiceId)
            @php $linkedInvoice = $invoices[$invoiceId] ?? null; @endphp
            @if($linkedInvoice)
                <div
                    x-show="openLinkedRef === 'invoice:{{ $invoiceId }}'"
                    x-cloak
                    class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Invoice: {{ $linkedInvoice['ref_no'] }}</h4>
                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['date']->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Order Ref</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['order_ref'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linkedInvoice['status']) }}">
                                    {{ $statusLabel($linkedInvoice['status']) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedInvoice['total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                            <dd class="font-mono font-medium {{ $linkedInvoice['outstanding'] > 0.005 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                £{{ number_format($linkedInvoice['outstanding'], 2) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Due Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['due_date'] ? $linkedInvoice['due_date']->format('d M Y') : '—' }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
        @endforeach
    </div>
@else
    @php
        $relatedInvoiceIds = collect($row['details']['allocations'])->pluck('invoice_id')->filter()->unique();
    @endphp
    <div class="grid gap-4" :class="openLinkedRef ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800/50">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs lg:grid-cols-3">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Method</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['details']['method'] }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['date']->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($row['status']) }}">
                            {{ $statusLabel($row['status']) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Total Amount</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['total'], 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Allocated</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['allocated'], 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Unallocated</dt>
                    <dd class="font-mono font-medium {{ $row['details']['unallocated'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        £{{ number_format($row['details']['unallocated'], 2) }}
                    </dd>
                </div>
            </dl>

            <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-white/10">
                @if(empty($row['details']['allocations']))
                    @if($row['status'] === 'drawn')
                        <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-400">
                            This payment has been fully transferred to other payments and is not directly allocated to any invoice.
                        </div>
                    @else
                        <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-400">
                            This payment has not yet been allocated to any invoice.
                        </div>
                    @endif
                @else
                    <p class="mb-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300">Invoices settled by this payment</p>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/[0.06]">
                            @foreach($row['details']['allocations'] as $settled)
                                <tr>
                                    <td class="py-1">
                                        <button
                                            type="button"
                                            class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                            x-on:click="openLinkedRef = openLinkedRef === 'invoice:{{ $settled['invoice_id'] }}' ? null : 'invoice:{{ $settled['invoice_id'] }}'"
                                        >
                                            {{ $settled['ref'] }}
                                        </button>
                                    </td>
                                    <td class="py-1 text-zinc-500 dark:text-zinc-400">{{ $settled['date']->format('d M Y') }}</td>
                                    <td class="py-1 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($settled['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @foreach($relatedInvoiceIds as $invoiceId)
            @php $linkedInvoice = $invoices[$invoiceId] ?? null; @endphp
            @if($linkedInvoice)
                <div
                    x-show="openLinkedRef === 'invoice:{{ $invoiceId }}'"
                    x-cloak
                    class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Invoice: {{ $linkedInvoice['ref_no'] }}</h4>
                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['date']->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Order Ref</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['order_ref'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linkedInvoice['status']) }}">
                                    {{ $statusLabel($linkedInvoice['status']) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linkedInvoice['total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                            <dd class="font-mono font-medium {{ $linkedInvoice['outstanding'] > 0.005 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                £{{ number_format($linkedInvoice['outstanding'], 2) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Due Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linkedInvoice['due_date'] ? $linkedInvoice['due_date']->format('d M Y') : '—' }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
        @endforeach
    </div>
@endif
