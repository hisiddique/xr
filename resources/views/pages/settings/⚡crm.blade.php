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

    public string $company_director_dn = '';
    public string $company_registration_no = '';
    public string $company_vat_no = '';
    public string $company_iso_cert = '';
    public string $certificate_of_conformity_no = '';
    public string $company_registered_address = '';
    public string $retention_of_title_clause = '';
    public string $dn_prefix = 'DN';
    public string $inv_prefix = 'INV';
    public string $cn_prefix = 'CN';
    public string $number_padding = '4';
    public string $dn_start_number = '1';
    public string $cn_start_number = '1';
    public string $app_font_size = '18';
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
        $this->company_director_dn = (string) Setting::get('company_director_dn', '');
        $this->company_registration_no = (string) Setting::get('company_registration_no', '');
        $this->company_vat_no = (string) Setting::get('company_vat_no', '');
        $this->company_iso_cert = (string) Setting::get('company_iso_cert', '');
        $this->certificate_of_conformity_no = (string) Setting::get('certificate_of_conformity_no', '');
        $this->company_registered_address = (string) Setting::get('company_registered_address', '');
        $this->retention_of_title_clause = (string) Setting::get('retention_of_title_clause', '');
        $this->dn_prefix = (string) Setting::get('dn_prefix', 'DN');
        $this->inv_prefix = (string) Setting::get('inv_prefix', 'INV');
        $this->cn_prefix = (string) Setting::get('cn_prefix', 'CN');
        $this->number_padding = (string) Setting::get('number_padding', '4');
        $this->dn_start_number = (string) Setting::get('dn_start_number', '1');
        $this->cn_start_number = (string) Setting::get('cn_start_number', '1');
        $this->app_font_size = (string) Setting::get('app_font_size', 18);
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
            'company_director_dn' => 'nullable|string|max:255',
            'company_registration_no' => 'nullable|string|max:100',
            'company_vat_no' => 'nullable|string|max:100',
            'company_iso_cert' => 'nullable|string|max:100',
            'certificate_of_conformity_no' => 'nullable|string|max:100',
            'company_registered_address' => 'nullable|string|max:500',
            'retention_of_title_clause' => 'nullable|string|max:2000',
            'dn_prefix' => 'required|string|max:10|alpha',
            'inv_prefix' => 'required|string|max:10|alpha',
            'cn_prefix' => 'required|string|max:10|alpha',
            'number_padding' => 'required|integer|min:1|max:10',
            'dn_start_number' => 'required|integer|min:1',
            'cn_start_number' => 'required|integer|min:1',
            'app_font_size' => 'required|integer|min:14|max:22',
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
        Setting::set('company_director_dn', $this->company_director_dn);
        Setting::set('company_registration_no', $this->company_registration_no);
        Setting::set('company_vat_no', $this->company_vat_no);
        Setting::set('company_iso_cert', $this->company_iso_cert);
        Setting::set('certificate_of_conformity_no', $this->certificate_of_conformity_no);
        Setting::set('company_registered_address', $this->company_registered_address);
        Setting::set('retention_of_title_clause', $this->retention_of_title_clause);
        Setting::set('dn_prefix', strtoupper($this->dn_prefix));
        Setting::set('inv_prefix', strtoupper($this->inv_prefix));
        Setting::set('cn_prefix', strtoupper($this->cn_prefix));
        Setting::set('number_padding', $this->number_padding, 'integer');
        Setting::set('dn_start_number', $this->dn_start_number, 'integer');
        Setting::set('cn_start_number', $this->cn_start_number, 'integer');
        Setting::set('app_font_size', $this->app_font_size, 'integer');

        $this->dispatch('font-size-saved', size: (int) $this->app_font_size);

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

    public function with(): array
    {
        return [
            'currentLogoPath' => (string) Setting::get('company_logo_path'),
        ];
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="CRM Settings"
        subtitle="Configure your company details, tax, and document numbering."
    />

    <form wire:submit="save" x-data="formNav" x-on:keydown="handleKey($event)" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        {{-- Branding / Logo --}}
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Company Logo</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Shown on PDFs and email templates. PNG, JPG or SVG up to 2MB.</p>
            </div>
            <div class="space-y-4">
                @if($currentLogoPath)
                    <div class="flex items-center gap-4">
                        <img
                            src="{{ Storage::disk('public')->url($currentLogoPath) }}"
                            alt="Company logo"
                            class="h-16 max-w-[240px] rounded-lg border border-zinc-200 object-contain p-2 dark:border-white/10"
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
                        <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" class="h-16 max-w-[240px] rounded-lg border border-zinc-200 object-contain p-2 dark:border-white/10">
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
                </div>
            </div>
        </div>

        {{-- Company --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Company Information</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Identity shown at the top of every document.</p>
            </div>
            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="company_name" :label="__('Company Name')" />
                    <flux:input wire:model="company_tagline" :label="__('Tagline')" :placeholder="__('Specialists in all types of Industrial Items')" />
                </div>
                <flux:textarea wire:model="company_address" :label="__('Company Address')" rows="3" :description="__('Appears in the header of documents (one line per row).')" />
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <flux:input wire:model="company_email" type="email" :label="__('Sales Email')" />
                    <flux:input wire:model="company_email_accounts" type="email" :label="__('Accounts Email')" />
                    <flux:input wire:model="company_tel_sales" :label="__('Tel Sales')" :placeholder="__('01234 567890')" />
                    <flux:input wire:model="company_tel_accounts" :label="__('Tel Accounts')" :placeholder="__('01234 567890')" />
                </div>
            </div>
        </div>

        {{-- Documents --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">PDF Footer &amp; Certificate</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Shown in the certificate block and footer of printed delivery notes and invoices.</p>
            </div>
            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="company_director" :label="__('Director Name (Invoices)')" :description="__('Shown on invoice PDF footer.')" />
                    <flux:input wire:model="company_director_dn" :label="__('Director Name (Delivery Notes)')" :description="__('Shown on delivery note PDF footer.')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <flux:input wire:model="company_registration_no" :label="__('Companies House No.')" />
                    <flux:input wire:model="company_vat_no" :label="__('VAT Number')" />
                    <flux:input wire:model="company_iso_cert" :label="__('ISO Certification')" :placeholder="__('ISO 9001-A1XXXX')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="certificate_of_conformity_no" :label="__('Certificate of Conformity No.')" :description="__('Appears next to “CERTIFICATE OF CONFORMITY” on delivery notes.')" />
                    <flux:textarea wire:model="company_registered_address" :label="__('Registered Address (Footer)')" rows="2" :description="__('Shown at the very bottom of the PDF.')" />
                </div>
                <flux:textarea wire:model="retention_of_title_clause" :label="__('Retention of Title Clause')" rows="4" :description="__('Legal terms printed on delivery notes. Text in double quotes is bolded.')" />
            </div>
        </div>

        {{-- Tax + Numbering combined into one row --}}
        <div class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">VAT &amp; Document Numbering</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Set the VAT rate and prefix/padding used to generate doc numbers.</p>
            </div>
            <div class="space-y-3">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-7">
                    <flux:input
                        wire:model="vat_rate"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        :label="__('VAT Rate (%)')"
                    />
                    <flux:input wire:model="dn_prefix" :label="__('DN Prefix')" :placeholder="__('DN')" maxlength="10" />
                    <flux:input wire:model="inv_prefix" :label="__('Invoice Prefix')" :placeholder="__('INV')" maxlength="10" />
                    <flux:input wire:model="cn_prefix" :label="__('Credit Note Prefix')" :placeholder="__('CN')" maxlength="10" />
                    <flux:input wire:model="number_padding" type="number" min="1" max="10" :label="__('Padding')" :description="__('e.g. 4 → 0001')" />
                    <flux:input wire:model="dn_start_number" type="number" min="1" :label="__('DN Start Number')" :description="__('First DN sequence number.')" />
                    <flux:input wire:model="cn_start_number" type="number" min="1" :label="__('Credit Note Start Number')" :description="__('First CN sequence number.')" />
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                    Preview: {{ strtoupper($dn_prefix).'-'.str_pad($dn_start_number ?: '1', (int) $number_padding, '0', STR_PAD_LEFT) }}
                    &middot;
                    {{ strtoupper($inv_prefix).'-'.str_pad($dn_start_number ?: '1', (int) $number_padding, '0', STR_PAD_LEFT) }}
                </p>
            </div>
        </div>

        {{-- Appearance --}}
        <div
            class="grid gap-6 border-t border-zinc-200/70 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10 dark:border-white/10"
            x-data="fontSizePreview({{ (int) $app_font_size }})"
        >
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Interface Font Size</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Scales all text across the app proportionally. Drag to preview live. Sign-in pages keep the default size.</p>
            </div>
            <div class="space-y-3">
                <div class="flex items-center gap-4">
                    <span class="text-xs text-zinc-400">14</span>
                    <input
                        type="range"
                        min="14"
                        max="22"
                        step="1"
                        x-model.number="size"
                        x-on:change="$wire.set('app_font_size', String(size), false)"
                        class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-zinc-200 accent-indigo-600 dark:bg-zinc-700"
                    >
                    <span class="text-xs text-zinc-400">22</span>
                    <span class="w-12 text-right font-mono text-sm font-semibold text-zinc-900 dark:text-white" x-text="size + 'px'"></span>
                </div>
                <p class="text-zinc-500 dark:text-zinc-400">The quick brown fox jumps over the lazy dog — 1234567890.</p>
                @error('app_font_size') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Footer actions --}}
        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <flux:button variant="primary" type="submit">Save Settings</flux:button>
        </div>
    </form>

</div>
