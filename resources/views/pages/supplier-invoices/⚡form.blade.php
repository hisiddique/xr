<?php

use App\Models\ExpenseCategory;
use App\Models\LookupPaymentMethod;
use App\Models\Overhead;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\SupplierCategory;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Supplier Invoice')] class extends Component {
    use WithFileUploads;

    public ?SupplierInvoice $supplierInvoice = null;

    public ?int $supplier_id = null;
    public string $supplierName = '';
    public string $supplier_ref_invoice_no = '';
    public string $invoice_date = '';
    public string $notes = '';
    public bool $addOverheadExpense = false;
    public ?int $overhead_category_id = null;
    public string $overhead_payment_method = '';
    public bool $overhead_has_vat = true;
    public float $vatRate = 20.0;
    public array $items = [];
    public array $existingAttachments = [];
    public array $markedForDeletion = [];
    public $newAttachments = [];
    public array $selectedDebitNoteIds = [];

    public function mount(): void
    {
        $this->vatRate = (float) Setting::get('vat_rate', 20);

        if ($this->supplierInvoice) {
            $this->supplierInvoice->load(['supplier', 'items', 'overhead']);
            $this->supplier_id = $this->supplierInvoice->supplier_id;
            $this->supplierName = $this->supplierInvoice->supplier->typeahead_label;
            $this->supplier_ref_invoice_no = $this->supplierInvoice->supplier_ref_invoice_no ?? '';
            $this->invoice_date = $this->supplierInvoice->invoice_date->format('Y-m-d');
            $this->notes = $this->supplierInvoice->notes ?? '';
            $this->existingAttachments = $this->supplierInvoice->attachments ?? [];

            // NOTE: if the supplier's category has since changed away from OverheadExpenses,
            // this hydrated state remains but the checkbox/card are hidden by the Blade's
            // supplierCategory guard. save() still preserves the existing linked Overhead
            // untouched in that case.
            if ($this->supplierInvoice->overhead) {
                $overhead = $this->supplierInvoice->overhead;
                $this->addOverheadExpense = true;
                $this->overhead_category_id = $overhead->category_id;
                $this->overhead_payment_method = $overhead->payment_method;
                $this->overhead_has_vat = (bool) $overhead->has_vat;
            }

            $this->items = $this->supplierInvoice->items->map(fn ($item) => [
                'product_code' => $item->product_code ?? '',
                'quantity' => (float) $item->quantity,
                'unit_amount' => (float) $item->unit_amount,
                'vat_applicable' => (bool) $item->vat_applicable,
            ])->toArray();
            $this->selectedDebitNoteIds = $this->supplierInvoice->debitNotes()->pluck('supplier_debit_notes.id')->toArray();
        } else {
            $this->invoice_date = now()->format('Y-m-d');
            $this->items = [['product_code' => '', 'quantity' => 1, 'unit_amount' => '', 'vat_applicable' => true]];
        }
    }

    public function toggleDeletion(int $index): void
    {
        if (in_array($index, $this->markedForDeletion)) {
            $this->markedForDeletion = array_values(
                array_filter($this->markedForDeletion, fn ($i) => $i !== $index)
            );
        } else {
            $this->markedForDeletion[] = $index;
        }
    }

    public function cancelNewAttachment(int $index): void
    {
        if (isset($this->newAttachments[$index])) {
            unset($this->newAttachments[$index]);
            $this->newAttachments = array_values($this->newAttachments);
        }
    }

    #[Computed]
    public function availableDebitNotes(): array
    {
        if (! $this->supplier_id) {
            return [];
        }

        return \App\Models\SupplierDebitNote::where('supplier_id', $this->supplier_id)
            ->whereDoesntHave('appliedInvoices')
            ->where('status', 'committed')
            ->orderBy('doc_date')
            ->get(['id', 'reference', 'total', 'doc_date'])
            ->toArray();
    }

    #[Computed]
    public function appliedDebitNotes(): array
    {
        if (! $this->supplierInvoice) {
            return [];
        }

        return $this->supplierInvoice->debitNotes()
            ->withPivot('applied_amount', 'applied_at')
            ->get()
            ->toArray();
    }

    #[Computed]
    public function selectedDebitNoteTotal(): float
    {
        if (empty($this->selectedDebitNoteIds)) {
            return 0.0;
        }

        $available = collect($this->availableDebitNotes)->keyBy('id');
        $applied = collect($this->appliedDebitNotes)->keyBy('id');

        return (float) collect($this->selectedDebitNoteIds)->sum(function ($id) use ($available, $applied) {
            if ($available->has($id)) {
                return (float) $available->get($id)['total'];
            }
            if ($applied->has($id)) {
                return (float) $applied->get($id)['pivot']['applied_amount'];
            }

            return 0.0;
        });
    }

    #[Computed]
    public function supplierCategory(): ?SupplierCategory
    {
        if (! $this->supplier_id) {
            return null;
        }

        return Supplier::withTrashed()->find($this->supplier_id)?->category;
    }

    #[Computed]
    public function overheadCategories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    #[Computed]
    public function paymentMethods()
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    public function save(): void
    {
        $this->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'supplier_ref_invoice_no' => 'nullable|string|max:100',
            'invoice_date' => 'required|date',
            'notes' => 'nullable|string|max:5000',
            'overhead_category_id' => [Rule::requiredIf(fn () => $this->addOverheadExpense), 'nullable', 'integer', 'exists:expense_categories,id'],
            'overhead_payment_method' => [Rule::requiredIf(fn () => $this->addOverheadExpense), 'nullable', 'string', 'max:50'],
            'overhead_has_vat' => 'boolean',
            'newAttachments.*' => 'nullable|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ]);

        $data = [
            'supplier_id' => $this->supplier_id,
            'supplier_ref_invoice_no' => $this->supplier_ref_invoice_no ?: null,
            'invoice_date' => $this->invoice_date,
            'notes' => $this->notes ?: null,
            'status' => 'posted',
        ];

        if ($this->supplierInvoice === null) {
            $invoice = SupplierInvoice::create(array_merge($data, ['created_by' => auth()->id()]));
        } else {
            $this->supplierInvoice->update($data);
            $invoice = $this->supplierInvoice;
        }

        $this->items = array_values(array_filter($this->items, fn ($row) => (float) ($row['unit_amount'] ?? 0) > 0));

        $invoice->items()->delete();
        foreach ($this->items as $i => $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $unit = (float) ($row['unit_amount'] ?? 0);
            $invoice->items()->create([
                'product_code' => null,
                'quantity' => $qty,
                'unit_amount' => $unit,
                'vat_applicable' => (bool) ($row['vat_applicable'] ?? false),
                'line_total' => round($qty * $unit, 2),
                'sort_order' => $i,
            ]);
        }

        $invoice->unsetRelation('items');

        if ($this->addOverheadExpense) {
            $grossTotal = $invoice->grossTotal;

            if ($grossTotal > 0) {
                $overheadData = [
                    'category_id' => $this->overhead_category_id,
                    'expense_date' => $this->invoice_date,
                    'amount' => $grossTotal,
                    'has_vat' => $this->overhead_has_vat,
                    'payment_method' => $this->overhead_payment_method,
                ];

                if ($invoice->overhead_id) {
                    $invoice->overhead->update($overheadData);
                } else {
                    $overhead = Overhead::create($overheadData);
                    $invoice->update(['overhead_id' => $overhead->id]);
                }
            } elseif ($invoice->overhead_id) {
                $overheadToDelete = $invoice->overhead;
                $invoice->update(['overhead_id' => null]);
                $overheadToDelete->delete();
            }
        } elseif ($invoice->overhead_id) {
            $overheadToDelete = $invoice->overhead;
            $invoice->update(['overhead_id' => null]);
            $overheadToDelete->delete();
        }

        foreach ($this->markedForDeletion as $idx) {
            if (isset($this->existingAttachments[$idx])) {
                Storage::disk('public')->delete($this->existingAttachments[$idx]['path']);
            }
        }
        $this->existingAttachments = array_values(
            array_filter($this->existingAttachments, fn ($att, $i) => ! in_array($i, $this->markedForDeletion), ARRAY_FILTER_USE_BOTH)
        );

        $attachments = $this->existingAttachments;
        foreach (($this->newAttachments ?? []) as $file) {
            if (! $file) {
                continue;
            }
            $path = $file->store('supplier-invoice-attachments', 'public');
            $attachments[] = [
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        }
        $invoice->update(['attachments' => $attachments ?: null]);

        if (! empty($this->selectedDebitNoteIds)) {
            $toDetach = $invoice->debitNotes()->pluck('supplier_debit_notes.id')->toArray();
            $invoice->debitNotes()->detach($toDetach);

            foreach ($this->selectedDebitNoteIds as $dnId) {
                $dn = \App\Models\SupplierDebitNote::find($dnId);
                if ($dn) {
                    $invoice->debitNotes()->attach($dnId, [
                        'applied_amount' => $dn->total,
                        'applied_at' => now(),
                    ]);
                }
            }
        } else {
            $invoice->debitNotes()->detach();
        }

        Flux::toast(variant: 'success', text: $this->supplierInvoice === null ? 'Supplier invoice created.' : 'Supplier invoice updated.');
        $this->redirect(route('supplier-invoices.show', $invoice), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        :title="$supplierInvoice ? 'Edit: '.$supplierInvoice->reference : 'New Supplier Invoice'"
        :subtitle="$supplierInvoice ? 'Update supplier invoice details and line items.' : 'Record a supplier invoice with line items and attachments.'"
    >
        <x-slot:action>
            <flux:button
                variant="ghost"
                icon="arrow-left"
                :href="$supplierInvoice ? route('supplier-invoices.show', $supplierInvoice) : route('supplier-invoices.index')"
                wire:navigate
            >
                Back
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">

        {{-- Left column: attachments --}}
        <div class="flex flex-1 flex-col gap-4">

            {{-- Drag & drop zone --}}
            <div
                x-data="{
                    isDragging: false,
                    handleDrop(e) {
                        this.isDragging = false;
                        const files = Array.from(e.dataTransfer?.files ?? []);
                        if (files.length) {
                            const input = $refs.fileInput;
                            const dt = new DataTransfer();
                            files.forEach(f => dt.items.add(f));
                            input.files = dt.files;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                }"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop($event)"
                :class="isDragging ? 'border-indigo-400 bg-indigo-50/40 dark:border-indigo-500 dark:bg-indigo-500/10' : 'border-zinc-300 dark:border-white/20'"
                class="flex min-h-48 flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed p-6 transition-colors"
            >
                <flux:icon.cloud-arrow-up class="size-10 text-zinc-400" />
                <div class="text-center">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Drop invoice receipts here</p>
                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">PDF, PNG, JPG up to 10MB each</p>
                </div>
                <flux:button variant="ghost" size="sm" type="button" @click="$refs.fileInput.click()">Browse Files</flux:button>
                <input
                    x-ref="fileInput"
                    type="file"
                    multiple
                    wire:model="newAttachments"
                    accept=".pdf,.png,.jpg,.jpeg"
                    class="hidden"
                />
            </div>

            {{-- Existing attachments --}}
            @if($existingAttachments)
                <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
                    @foreach($existingAttachments as $i => $att)
                        @if(in_array($i, $markedForDeletion))
                            <div wire:key="att-{{ $i }}" class="flex items-center gap-3 border-b border-red-100/70 bg-red-50/60 px-3 py-2.5 last:border-b-0 transition-all duration-300 dark:border-red-500/10 dark:bg-red-500/10">
                                <flux:icon.document class="size-4 shrink-0 text-red-400" />
                                <span class="min-w-0 flex-1 truncate text-sm text-red-400 line-through dark:text-red-400">{{ $att['original_name'] }}</span>
                                <button wire:click="toggleDeletion({{ $i }})" type="button" title="Restore" class="shrink-0 text-red-400 transition-colors hover:text-emerald-500">
                                    <flux:icon.arrow-uturn-left class="size-4" />
                                </button>
                            </div>
                        @else
                            <div wire:key="att-{{ $i }}" class="flex items-center gap-3 border-b border-zinc-100 px-3 py-2.5 last:border-b-0 transition-all duration-300 dark:border-white/[0.06]">
                                <flux:icon.document class="size-4 shrink-0 text-zinc-400" />
                                <span class="min-w-0 flex-1 truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $att['original_name'] }}</span>
                                <button wire:click="toggleDeletion({{ $i }})" type="button" class="shrink-0 text-zinc-400 transition-colors hover:text-red-500">
                                    <flux:icon.x-mark class="size-4" />
                                </button>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Queued new uploads --}}
            @if($newAttachments)
                <div class="overflow-hidden rounded-2xl border border-emerald-200/70 bg-emerald-50/50 dark:border-emerald-500/20 dark:bg-emerald-500/5">
                    @foreach($newAttachments as $j => $file)
                        @if($file)
                            <div wire:key="new-{{ $j }}" class="flex items-center gap-3 border-b border-emerald-100/70 px-3 py-2.5 last:border-b-0 transition-all duration-300 dark:border-emerald-500/10">
                                <flux:icon.document-plus class="size-4 shrink-0 text-emerald-500" />
                                <span class="min-w-0 flex-1 truncate text-sm text-emerald-700 dark:text-emerald-400">{{ $file->getClientOriginalName() }}</span>
                                <button wire:click="cancelNewAttachment({{ $j }})" type="button" class="shrink-0 text-emerald-400 transition-colors hover:text-red-500">
                                    <flux:icon.x-mark class="size-4" />
                                </button>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

        </div>

        {{-- Right column --}}
        <div
            class="flex flex-[2] flex-col gap-4"
            data-form-root
            x-data="supplierInvoiceLineForm(@js($items), {{ $vatRate }})"
            x-on:keydown="handleKey($event)"
            x-on:exit-confirm-save.window="$wire.set('items', rows, false); $wire.save()"
            x-on:exit-confirm-discard.window="window.location.href = '{{ $supplierInvoice ? route('supplier-invoices.show', $supplierInvoice) : route('supplier-invoices.index') }}'"
        >

            {{-- Card 1: Invoice Details --}}
            <div
                class="rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900"
            >
                <div class="border-b border-zinc-200/70 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Invoice Details</h2>
                </div>
                <div class="grid gap-4 p-4 md:grid-cols-2" data-form-grid>

                    <livewire:pages::ui.typeahead
                        :key="'typeahead-supplier'"
                        wire:model.live="supplier_id"
                        model="App\Models\Supplier"
                        column="company_name"
                        :search-columns="['company_name', 'reference']"
                        label-accessor="typeahead_label"
                        :min-chars="2"
                        :label="__('Supplier')"
                        :placeholder="__('Search supplier (2+ letters)…')"
                        :selected-label="$supplierName"
                        error-name="supplier_id"
                        required
                    />

                    <flux:input wire:model="invoice_date" type="date" :label="__('Invoice Date')" required />

                    <flux:input wire:model="supplier_ref_invoice_no" :label="__('Supplier Invoice Number')" />
                    @error('supplier_ref_invoice_no') <flux:error>{{ $message }}</flux:error> @enderror

                    @if($this->supplierCategory)
                        <flux:input
                            :value="$this->supplierCategory->label()"
                            :label="__('Supplier Category')"
                            disabled
                            readonly
                        />
                    @endif

                    @if($this->supplierCategory === \App\SupplierCategory::OverheadExpenses)
                        <div class="md:col-span-2 flex items-center gap-3">
                            <flux:checkbox wire:model.live="addOverheadExpense" id="add_overhead_expense" />
                            <flux:label for="add_overhead_expense">{{ __('Add Overhead Expense') }}</flux:label>
                        </div>
                    @endif

                    @if($this->supplierCategory === \App\SupplierCategory::OverheadExpenses && $addOverheadExpense)
                        <div class="md:col-span-2 rounded-lg border border-sky-200 bg-sky-50 p-4 dark:border-sky-700/30 dark:bg-sky-500/5">
                            <h3 class="mb-3 text-sm font-semibold text-sky-800 dark:text-sky-400">Overhead Expense Details</h3>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <flux:label>{{ __('Overhead Expense Category') }} <span class="text-red-500">*</span></flux:label>
                                    <flux:select wire:model="overhead_category_id" class="mt-1.5">
                                        <flux:select.option value="">— Select category —</flux:select.option>
                                        @foreach($this->overheadCategories as $category)
                                            <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('overhead_category_id') <flux:error>{{ $message }}</flux:error> @enderror
                                </div>
                                <div>
                                    <flux:label>{{ __('Payment Method') }} <span class="text-red-500">*</span></flux:label>
                                    <flux:select wire:model="overhead_payment_method" class="mt-1.5">
                                        <flux:select.option value="">— Select method —</flux:select.option>
                                        @foreach($this->paymentMethods as $method)
                                            <flux:select.option value="{{ $method->name }}">{{ $method->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('overhead_payment_method') <flux:error>{{ $message }}</flux:error> @enderror
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-3">
                                <flux:checkbox wire:model="overhead_has_vat" id="overhead_has_vat" />
                                <flux:label for="overhead_has_vat">{{ __('Include VAT') }}</flux:label>
                            </div>
                        </div>
                    @endif

                    <div class="md:col-span-2">
                        <flux:textarea
                            wire:model="notes"
                            :label="__('Internal Remittance Comments / Auditing Notes')"
                            rows="3"
                            :placeholder="__('Optional internal notes…')"
                        />
                    </div>

                    @if($supplier_id && count($this->availableDebitNotes) > 0)
                        <div class="md:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700/30 dark:bg-amber-500/5">
                            <h3 class="mb-3 text-sm font-semibold text-amber-800 dark:text-amber-400">Available Debit Notes — Apply to This Invoice</h3>
                            <div class="flex flex-col gap-2">
                                @foreach($this->availableDebitNotes as $dn)
                                    <label class="flex cursor-pointer items-center gap-3 text-sm">
                                        <flux:checkbox wire:model.live="selectedDebitNoteIds" :value="$dn['id']" />
                                        <span class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">{{ $dn['reference'] }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($dn['doc_date'])->format('d M Y') }}</span>
                                        <span class="ml-auto font-mono font-semibold text-red-600 dark:text-red-400">
                                            −£{{ number_format($dn['total'], 2) }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($supplierInvoice && count($this->appliedDebitNotes) > 0)
                        <div class="md:col-span-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                            <h3 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Applied Debit Notes</h3>
                            <div class="flex flex-col gap-2">
                                @foreach($this->appliedDebitNotes as $dn)
                                    <div class="flex items-center justify-between text-sm">
                                        <div class="flex items-center gap-3">
                                            <flux:checkbox wire:model.live="selectedDebitNoteIds" :value="$dn['id']" />
                                            <span class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">{{ $dn['reference'] }}</span>
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($dn['doc_date'])->format('d M Y') }}</span>
                                        </div>
                                        <span class="font-mono font-semibold text-red-600 dark:text-red-400">
                                            −£{{ number_format($dn['pivot']['applied_amount'], 2) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>
            </div>

            {{-- Cards 2 (line items) + 3 (summary) + save bar --}}
            <div class="flex flex-col gap-4">

                {{-- Card 2: Line Items --}}
                <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Line Items</h2>
                        <flux:button type="button" variant="ghost" icon="plus" size="sm" x-on:click="add()">Add Line</flux:button>
                    </div>

                    <div class="overflow-x-auto" data-items-table>
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Unit Net (£)</th>
                                    <th class="w-48 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT</th>
                                    <th class="w-48 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Gross (£)</th>
                                    <th class="w-10 px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody x-ref="rowsBody" class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                                <template x-for="(row, i) in rows" :key="i">
                                    <tr :data-row-idx="i">
                                        <td class="px-4 py-2.5">
                                            <input
                                                type="number"
                                                x-model.number="row.unit_amount"
                                                data-row-details
                                                step="0.01"
                                                min="0"
                                                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 font-mono text-sm text-zinc-900 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                            />
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <select
                                                x-model.boolean="row.vat_applicable"
                                                x-on:focus="$el.showPicker?.()"
                                                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                            >
                                                <option :value="true">Yes (20%)</option>
                                                <option :value="false">No</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <span class="font-mono text-sm" x-text="'£' + lineGross(row).toFixed(2)"></span>
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <flux:button size="xs" variant="ghost" icon="x-mark" type="button" x-on:click="remove(i)" x-show="rows.length > 1" data-row-remove />
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Card 3: Summary --}}
                <div class="rounded-2xl border border-violet-200/70 bg-gradient-to-br from-violet-50 to-indigo-50 p-5 dark:border-violet-500/20 dark:from-violet-500/10 dark:to-indigo-500/10">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-violet-700 dark:text-violet-400">Ledger Statement Summary Balances</p>
                    <dl class="space-y-3">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-zinc-600 dark:text-zinc-400">Net Accumulated Cost Base Total</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white" x-text="'£' + netTotal.toFixed(2)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-zinc-600 dark:text-zinc-400">Calculated VAT Input Pool Element (20%)</dt>
                            <dd class="font-mono font-medium text-zinc-900 dark:text-white" x-text="'£' + vatTotal.toFixed(2)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t border-violet-200/70 pt-3 dark:border-violet-500/20">
                            <dt class="text-base font-semibold text-zinc-900 dark:text-white">Total Final Payable Gross Sum</dt>
                            <dd class="font-mono text-lg font-bold text-violet-700 dark:text-violet-400" x-text="'£' + grossTotal.toFixed(2)"></dd>
                        </div>
                        @if($this->selectedDebitNoteTotal > 0)
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-sm text-zinc-600 dark:text-zinc-400">Debit Note Deductions</dt>
                            <dd class="font-mono font-medium text-red-600 dark:text-red-400">−£{{ number_format($this->selectedDebitNoteTotal, 2) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t border-violet-200/70 pt-3 dark:border-violet-500/20">
                            <dt class="text-base font-semibold text-zinc-900 dark:text-white">Net Payable After Deductions</dt>
                            <dd class="font-mono text-lg font-bold text-emerald-600 dark:text-emerald-400" x-text="'£' + Math.max(0, grossTotal - {{ $this->selectedDebitNoteTotal }}).toFixed(2)"></dd>
                        </div>
                        @endif
                    </dl>
                </div>

                {{-- Sticky save bar --}}
                <div class="sticky bottom-0 z-10 flex items-center justify-end gap-3 rounded-2xl border border-zinc-200/70 bg-white/95 px-4 py-3 shadow-[0_-1px_4px_rgba(16,24,40,0.06)] backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
                    <flux:button
                        variant="ghost"
                        :href="$supplierInvoice ? route('supplier-invoices.show', $supplierInvoice) : route('supplier-invoices.index')"
                        wire:navigate
                        data-form-nav
                    >
                        Cancel
                    </flux:button>
                    <flux:button
                        variant="primary"
                        type="button"
                        @click="$wire.set('items', rows, false); $wire.save()"
                        data-form-nav
                        data-form-submit
                    >
                        {{ $supplierInvoice ? 'Save Changes' : 'Save Invoice' }}
                    </flux:button>
                </div>

            </div>

        </div>

    </div>

    <x-ui.exit-confirm-modal />

</div>
