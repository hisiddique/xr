<?php

use App\Models\Setting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('CRM Settings')] class extends Component {
    use WithFileUploads;

    public string $vat_rate = '20';
    public string $company_name = '';
    public string $company_tagline = '';
    public string $company_address = '';
    public string $company_email = '';
    public string $company_email_accounts = '';
    public string $company_tel_sales = '';
    public string $company_tel_accounts = '';
    public string $company_director = '';
    public string $company_registration_no = '';
    public string $company_vat_no = '';
    public string $company_iso_cert = '';
    public string $certificate_of_conformity_no = '';
    public string $company_registered_address = '';
    public string $retention_of_title_clause = '';
    public string $dn_prefix = 'DN';
    public string $inv_prefix = 'INV';
    public string $number_padding = '4';
    public $logo = null;

    public function mount(): void
    {
        $this->vat_rate = (string) Setting::get('vat_rate', '20');
        $this->company_name = (string) Setting::get('company_name', '');
        $this->company_tagline = (string) Setting::get('company_tagline', '');
        $this->company_address = (string) Setting::get('company_address', '');
        $this->company_email = (string) Setting::get('company_email', '');
        $this->company_email_accounts = (string) Setting::get('company_email_accounts', '');
        $this->company_tel_sales = (string) Setting::get('company_tel_sales', '');
        $this->company_tel_accounts = (string) Setting::get('company_tel_accounts', '');
        $this->company_director = (string) Setting::get('company_director', '');
        $this->company_registration_no = (string) Setting::get('company_registration_no', '');
        $this->company_vat_no = (string) Setting::get('company_vat_no', '');
        $this->company_iso_cert = (string) Setting::get('company_iso_cert', '');
        $this->certificate_of_conformity_no = (string) Setting::get('certificate_of_conformity_no', '');
        $this->company_registered_address = (string) Setting::get('company_registered_address', '');
        $this->retention_of_title_clause = (string) Setting::get('retention_of_title_clause', '');
        $this->dn_prefix = (string) Setting::get('dn_prefix', 'DN');
        $this->inv_prefix = (string) Setting::get('inv_prefix', 'INV');
        $this->number_padding = (string) Setting::get('number_padding', '4');
    }

    public function save(): void
    {
        $this->validate([
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'vat_rate' => 'required|numeric|min:0|max:100',
            'company_name' => 'nullable|string|max:255',
            'company_tagline' => 'nullable|string|max:255',
            'company_address' => 'nullable|string|max:500',
            'company_email' => 'nullable|email|max:255',
            'company_email_accounts' => 'nullable|email|max:255',
            'company_tel_sales' => 'nullable|string|max:100',
            'company_tel_accounts' => 'nullable|string|max:100',
            'company_director' => 'nullable|string|max:255',
            'company_registration_no' => 'nullable|string|max:100',
            'company_vat_no' => 'nullable|string|max:100',
            'company_iso_cert' => 'nullable|string|max:100',
            'certificate_of_conformity_no' => 'nullable|string|max:100',
            'company_registered_address' => 'nullable|string|max:500',
            'retention_of_title_clause' => 'nullable|string|max:2000',
            'dn_prefix' => 'required|string|max:10|alpha',
            'inv_prefix' => 'required|string|max:10|alpha',
            'number_padding' => 'required|integer|min:1|max:10',
        ]);

        if ($this->logo) {
            $existing = Setting::get('company_logo_path');
            if ($existing && Storage::disk('public')->exists($existing)) {
                Storage::disk('public')->delete($existing);
            }

            $path = $this->logo->store('company-logos', 'public');
            Setting::set('company_logo_path', $path);
            $this->logo = null;
        }

        Setting::flushCache();

        Setting::set('vat_rate', $this->vat_rate, 'float');
        Setting::set('company_name', $this->company_name);
        Setting::set('company_tagline', $this->company_tagline);
        Setting::set('company_address', $this->company_address);
        Setting::set('company_email', $this->company_email);
        Setting::set('company_email_accounts', $this->company_email_accounts);
        Setting::set('company_tel_sales', $this->company_tel_sales);
        Setting::set('company_tel_accounts', $this->company_tel_accounts);
        Setting::set('company_director', $this->company_director);
        Setting::set('company_registration_no', $this->company_registration_no);
        Setting::set('company_vat_no', $this->company_vat_no);
        Setting::set('company_iso_cert', $this->company_iso_cert);
        Setting::set('certificate_of_conformity_no', $this->certificate_of_conformity_no);
        Setting::set('company_registered_address', $this->company_registered_address);
        Setting::set('retention_of_title_clause', $this->retention_of_title_clause);
        Setting::set('dn_prefix', strtoupper($this->dn_prefix));
        Setting::set('inv_prefix', strtoupper($this->inv_prefix));
        Setting::set('number_padding', $this->number_padding, 'integer');

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function removeLogo(): void
    {
        $existing = Setting::get('company_logo_path');
        if ($existing && Storage::disk('public')->exists($existing)) {
            Storage::disk('public')->delete($existing);
        }

        Setting::set('company_logo_path', '');
        Setting::flushCache();

        Flux::toast(variant: 'success', text: __('Logo removed.'));
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="CRM Settings"
        subtitle="Configure your company details, tax, and document numbering."
    />

    <form wire:submit="save" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900 max-w-2xl">

        {{-- Company Logo --}}
        <div class="px-6 py-6">
            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Branding</p>
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Company Logo</h2>
            <div class="space-y-4">
                @php $currentLogoPath = \App\Models\Setting::get('company_logo_path'); @endphp

                @if($currentLogoPath)
                    <div class="flex items-center gap-4">
                        <img
                            src="{{ Storage::disk('public')->url($currentLogoPath) }}"
                            alt="Company logo"
                            class="h-16 max-w-[200px] rounded-lg border border-zinc-200 object-contain p-2 dark:border-white/10"
                        >
                        <flux:button
                            type="button"
                            wire:click="removeLogo"
                            variant="ghost"
                            size="sm"
                            class="text-red-500 hover:text-red-600"
                        >
                            Remove logo
                        </flux:button>
                    </div>
                @endif

                @if($logo && $logo->isPreviewable())
                    <div>
                        <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Preview:</p>
                        <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" class="h-16 max-w-[200px] rounded-lg border border-zinc-200 object-contain p-2 dark:border-white/10">
                    </div>
                @endif

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        {{ $currentLogoPath ? __('Replace Logo') : __('Upload Logo') }}
                    </label>
                    <input
                        type="file"
                        wire:model="logo"
                        accept="image/*"
                        class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-800 dark:file:text-zinc-300 dark:hover:file:bg-zinc-700"
                    >
                    @error('logo')
                        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    <p class="mt-1.5 text-xs text-zinc-400 dark:text-zinc-500">PNG, JPG or SVG. Max 2MB. Shown on invoices and emails.</p>
                </div>
            </div>
        </div>

        {{-- Company --}}
        <div class="border-t border-zinc-200/70 px-6 py-6 dark:border-white/10">
            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Company</p>
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Company Information</h2>
            <div class="space-y-4">
                <flux:input wire:model="company_name" :label="__('Company Name')" />
                <flux:input wire:model="company_tagline" :label="__('Tagline')" :description="__('Shown below the company name on documents. e.g. “Specialists in all types of Industrial Items”.')" />
                <flux:textarea wire:model="company_address" :label="__('Company Address')" rows="3" :description="__('Appears in the header of documents (one line per row).')" />
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="company_email" type="email" :label="__('Sales Email')" />
                    <flux:input wire:model="company_email_accounts" type="email" :label="__('Accounts Email')" />
                    <flux:input wire:model="company_tel_sales" :label="__('Tel Sales')" :placeholder="__('01234 567890')" />
                    <flux:input wire:model="company_tel_accounts" :label="__('Tel Accounts')" :placeholder="__('01234 567890')" />
                </div>
            </div>
        </div>

        {{-- Document Branding --}}
        <div class="border-t border-zinc-200/70 px-6 py-6 dark:border-white/10">
            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Documents</p>
            <h2 class="mb-1 text-sm font-semibold text-zinc-900 dark:text-white">PDF Footer &amp; Certificate</h2>
            <p class="mb-5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Shown in the certificate block and footer of printed delivery notes and invoices.') }}</p>
            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="company_director" :label="__('Director Name')" />
                    <flux:input wire:model="company_registration_no" :label="__('Companies House No.')" />
                    <flux:input wire:model="company_vat_no" :label="__('VAT Number')" />
                    <flux:input wire:model="company_iso_cert" :label="__('ISO Certification')" :placeholder="__('ISO 9001-A1XXXX')" />
                </div>
                <flux:input wire:model="certificate_of_conformity_no" :label="__('Certificate of Conformity No.')" :description="__('Appears next to “CERTIFICATE OF CONFORMITY” on delivery notes.')" />
                <flux:textarea wire:model="company_registered_address" :label="__('Registered Address (Footer)')" rows="2" :description="__('Shown at the very bottom of the PDF (e.g. registered office address).')" />
                <flux:textarea wire:model="retention_of_title_clause" :label="__('Retention of Title Clause')" rows="4" :description="__('Legal terms printed on delivery notes. Text in double quotes is bolded.')" />
            </div>
        </div>

        {{-- Tax --}}
        <div class="border-t border-zinc-200/70 px-6 py-6 dark:border-white/10">
            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Tax</p>
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">VAT Settings</h2>
            <flux:input
                wire:model="vat_rate"
                type="number"
                min="0"
                max="100"
                step="0.01"
                :label="__('VAT Rate (%)')"
                class="max-w-36"
            />
        </div>

        {{-- Numbering --}}
        <div class="border-t border-zinc-200/70 px-6 py-6 dark:border-white/10">
            <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Numbering</p>
            <h2 class="mb-5 text-sm font-semibold text-zinc-900 dark:text-white">Document Numbering</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input wire:model="dn_prefix" :label="__('DN Prefix')" :placeholder="__('DN')" maxlength="10" />
                <flux:input wire:model="inv_prefix" :label="__('Invoice Prefix')" :placeholder="__('INV')" maxlength="10" />
                <flux:input wire:model="number_padding" type="number" min="1" max="10" :label="__('Padding')" :description="__('E.g. 4 gives 0001')" />
            </div>
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                Preview: {{ strtoupper($dn_prefix).'-'.now()->year.'-'.str_pad('1', (int) $number_padding, '0', STR_PAD_LEFT) }}
                &mdash;
                {{ strtoupper($inv_prefix).'-'.now()->year.'-'.str_pad('1', (int) $number_padding, '0', STR_PAD_LEFT) }}
            </p>
        </div>

        {{-- Footer actions --}}
        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-6 py-4 dark:border-white/10 dark:bg-zinc-900/80">
            <flux:button variant="primary" type="submit">Save Settings</flux:button>
        </div>
    </form>

</div>
