@props(['row', 'invoices', 'debitNotes', 'payouts'])

@php
    $statusBadgeClasses = fn (string $status) => match ($status) {
        'applied', 'paid' => 'text-emerald-600 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'partial' => 'text-amber-600 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'unapplied', 'unpaid' => 'text-rose-600 bg-rose-50 border-rose-200 dark:text-rose-400 dark:bg-rose-500/10 dark:border-rose-500/20',
        default => 'text-zinc-600 bg-zinc-100 border-zinc-200 dark:text-zinc-300 dark:bg-zinc-800 dark:border-zinc-700',
    };
    $statusLabel = fn (string $status) => match ($status) {
        'applied' => 'Applied',
        'partial' => 'Partial',
        'unapplied' => 'Unapplied',
        'paid' => 'Paid',
        'unpaid' => 'Unpaid',
        'outstanding' => 'Outstanding',
        default => ucfirst($status),
    };
@endphp

@if($row['details']['kind'] === 'supplier_invoice')
    <div class="grid gap-4" :class="openLinkedRef ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800/50">
            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Invoice Details: {{ $row['ref_no'] }}</h4>

            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['date']->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Supplier Ref</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $row['supplier_ref'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['total'], 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Paid</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($row['details']['paid'], 2) }}</dd>
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
                                @if($allocation['kind'] === 'payout')
                                    <button
                                        type="button"
                                        class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                        x-on:click="openLinkedRef = openLinkedRef === 'payout:{{ $allocation['payout_id'] }}' ? null : 'payout:{{ $allocation['payout_id'] }}'"
                                    >
                                        {{ $allocation['ref'] }} ({{ $allocation['label'] }})
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                        x-on:click="openLinkedRef = openLinkedRef === 'debit_note:{{ $allocation['debit_note_id'] }}' ? null : 'debit_note:{{ $allocation['debit_note_id'] }}'"
                                    >
                                        {{ $allocation['ref'] }} ({{ $allocation['label'] }})
                                    </button>
                                @endif
                                <span class="font-mono text-zinc-600 dark:text-zinc-300">
                                    £{{ number_format($allocation['amount'], 2) }} &middot; {{ $allocation['date']->format('d M Y') }}
                                </span>
                            </li>
                            @if($allocation['deduction'] > 0.005)
                                <p class="text-xs text-zinc-400 dark:text-zinc-500">Incl. £{{ number_format($allocation['deduction'], 2) }} deducted{{ $allocation['deduction_ref'] ? ' via '.$allocation['deduction_ref'] : '' }}</p>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @foreach($row['details']['allocations'] as $allocation)
            @if($allocation['kind'] === 'payout')
                @php $linked = $payouts[$allocation['payout_id']] ?? null; @endphp
                @if($linked)
                    <div
                        x-show="openLinkedRef === 'payout:{{ $allocation['payout_id'] }}'"
                        x-cloak
                        class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Payout: {{ $allocation['ref'] }}</h4>
                            <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $allocation['date']->format('d M Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                                <dd>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linked['status']) }}">
                                        {{ $statusLabel($linked['status']) }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Allocated</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['allocated'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Unallocated</dt>
                                <dd class="font-mono font-medium {{ $linked['unallocated'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format($linked['unallocated'], 2) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif
            @else
                @php $linked = $debitNotes[$allocation['debit_note_id']] ?? null; @endphp
                @if($linked)
                    <div
                        x-show="openLinkedRef === 'debit_note:{{ $allocation['debit_note_id'] }}'"
                        x-cloak
                        class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Debit Note: {{ $allocation['ref'] }}</h4>
                            <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Date Issued</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['date']->format('d M Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                                <dd>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linked['status']) }}">
                                        {{ $statusLabel($linked['status']) }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Applied Total</dt>
                                <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['applied_total'], 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                                <dd class="font-mono font-medium {{ $linked['outstanding'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format($linked['outstanding'], 2) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endif
            @endif
        @endforeach
    </div>
@elseif($row['details']['kind'] === 'debit_note')
    @php
        $relatedInvoiceIds = collect([$row['details']['linked_invoice_id']])
            ->merge(collect($row['details']['applied_to'])->pluck('supplier_invoice_id'))
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
                    <dt class="text-zinc-500 dark:text-zinc-400">Debit Amount</dt>
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
                    <dt class="text-zinc-500 dark:text-zinc-400">Linked Invoice</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">
                        @if($row['details']['linked_invoice'])
                            <button
                                type="button"
                                class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                x-on:click="openLinkedRef = openLinkedRef === 'supplier_invoice:{{ $row['details']['linked_invoice_id'] }}' ? null : 'supplier_invoice:{{ $row['details']['linked_invoice_id'] }}'"
                            >
                                {{ $row['details']['linked_invoice'] }}
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
                                            x-on:click="openLinkedRef = openLinkedRef === 'supplier_invoice:{{ $applied['supplier_invoice_id'] }}' ? null : 'supplier_invoice:{{ $applied['supplier_invoice_id'] }}'"
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
            @php $linked = $invoices[$invoiceId] ?? null; @endphp
            @if($linked)
                <div
                    x-show="openLinkedRef === 'supplier_invoice:{{ $invoiceId }}'"
                    x-cloak
                    class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Invoice: {{ $linked['ref_no'] }}</h4>
                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['date']->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Supplier Ref</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['supplier_ref'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linked['status']) }}">
                                    {{ $statusLabel($linked['status']) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                            <dd class="font-mono font-medium {{ $linked['outstanding'] > 0.005 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                £{{ number_format($linked['outstanding'], 2) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif
        @endforeach
    </div>
@else
    @php
        $relatedInvoiceIds = collect($row['details']['allocations'])->where('target_kind', 'supplier_invoice')->pluck('supplier_invoice_id')->filter()->unique();
        $relatedDebitNoteIds = collect($row['details']['allocations'])->where('target_kind', 'debit_note')->pluck('debit_note_id')->filter()->unique();
    @endphp
    <div class="grid gap-4" :class="openLinkedRef ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
        <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800/50">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs lg:grid-cols-3">
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
                    <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-400">
                        This payout has not yet been allocated.
                    </div>
                @else
                    <p class="mb-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300">Documents settled by this payout</p>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/[0.06]">
                            @foreach($row['details']['allocations'] as $settled)
                                <tr>
                                    <td class="py-1">
                                        @if($settled['target_kind'] === 'supplier_invoice')
                                            <button
                                                type="button"
                                                class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                                x-on:click="openLinkedRef = openLinkedRef === 'supplier_invoice:{{ $settled['supplier_invoice_id'] }}' ? null : 'supplier_invoice:{{ $settled['supplier_invoice_id'] }}'"
                                            >
                                                {{ $settled['ref'] }}
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                class="cursor-pointer text-indigo-600 underline dark:text-indigo-400"
                                                x-on:click="openLinkedRef = openLinkedRef === 'debit_note:{{ $settled['debit_note_id'] }}' ? null : 'debit_note:{{ $settled['debit_note_id'] }}'"
                                            >
                                                {{ $settled['ref'] }}
                                            </button>
                                        @endif
                                    </td>
                                    <td class="py-1 text-zinc-500 dark:text-zinc-400">{{ $settled['date']->format('d M Y') }}</td>
                                    <td class="py-1 text-right font-mono text-zinc-900 dark:text-white">£{{ number_format($settled['amount'], 2) }}</td>
                                </tr>
                                @if($settled['deduction'] > 0.005)
                                    <tr>
                                        <td colspan="3" class="text-xs text-zinc-400 dark:text-zinc-500">Incl. £{{ number_format($settled['deduction'], 2) }} debit-note deduction</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @foreach($relatedInvoiceIds as $invoiceId)
            @php $linked = $invoices[$invoiceId] ?? null; @endphp
            @if($linked)
                <div
                    x-show="openLinkedRef === 'supplier_invoice:{{ $invoiceId }}'"
                    x-cloak
                    class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Invoice: {{ $linked['ref_no'] }}</h4>
                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Date</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['date']->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Supplier Ref</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['supplier_ref'] ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linked['status']) }}">
                                    {{ $statusLabel($linked['status']) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                            <dd class="font-mono font-medium {{ $linked['outstanding'] > 0.005 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                £{{ number_format($linked['outstanding'], 2) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif
        @endforeach

        @foreach($relatedDebitNoteIds as $debitNoteId)
            @php $linked = $debitNotes[$debitNoteId] ?? null; @endphp
            @if($linked)
                <div
                    x-show="openLinkedRef === 'debit_note:{{ $debitNoteId }}'"
                    x-cloak
                    class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Linked Debit Note: {{ $linked['ref_no'] }}</h4>
                        <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="openLinkedRef = null" />
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Date Issued</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $linked['date']->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                            <dd>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusBadgeClasses($linked['status']) }}">
                                    {{ $statusLabel($linked['status']) }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Applied Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white">£{{ number_format($linked['applied_total'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Outstanding</dt>
                            <dd class="font-mono font-medium {{ $linked['outstanding'] > 0.005 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                £{{ number_format($linked['outstanding'], 2) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif
        @endforeach
    </div>
@endif
