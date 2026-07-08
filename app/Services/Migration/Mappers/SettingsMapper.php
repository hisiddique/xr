<?php

namespace App\Services\Migration\Mappers;

use App\Models\Setting;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\EntityMapper;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class SettingsMapper implements EntityMapper
{
    /**
     * Normalized (lowercase, alphanumeric-only) legacy CompanySettings name => known Setting key.
     *
     * @var array<string, string>
     */
    private const KNOWN_SETTING_KEYS = [
        'vatrate' => 'vat_rate',
        'dnprefix' => 'dn_prefix',
        'invprefix' => 'inv_prefix',
        'cnprefix' => 'cn_prefix',
        'supprefix' => 'sup_prefix',
        'supinvprefix' => 'supinv_prefix',
        'numberpadding' => 'number_padding',
        'dnstartnumber' => 'dn_start_number',
        'cnstartnumber' => 'cn_start_number',
        'companydirectordn' => 'company_director_dn',
    ];

    public function key(): string
    {
        return 'settings';
    }

    public function label(): string
    {
        return 'Company & Settings';
    }

    public function count(): int
    {
        return 1 + DB::connection('legacy')->table('CompanySettings')->where('Status', 'A')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        $company = DB::connection('legacy')->table('Companies')->first();

        if ($company) {
            yield array_merge(['__source' => 'company'], (array) $company);
        }

        foreach (
            DB::connection('legacy')->table('CompanySettings')
                ->where('Status', 'A')
                ->orderBy('Seq')
                ->lazy($chunkSize) as $row
        ) {
            yield array_merge(['__source' => 'company_setting'], (array) $row);
        }
    }

    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        return match ($legacyRow['__source'] ?? null) {
            'company' => $this->applyCompany($legacyRow),
            'company_setting' => $this->applyCompanySetting($legacyRow),
            default => MapOutcome::Skipped,
        };
    }

    private function applyCompany(array $row): MapOutcome
    {
        $name = trim((string) ($row['CompanyName'] ?? ''));

        if ($name !== '') {
            Setting::set('company_name', $name);
        }

        $addressParts = array_filter([
            trim((string) ($row['add1'] ?? '').' '.(string) ($row['add2'] ?? '')),
            trim((string) ($row['county'] ?? '')),
            trim((string) ($row['pcode'] ?? '')),
            trim((string) ($row['country'] ?? '')),
        ], fn (string $part): bool => $part !== '');

        if ($addressParts !== []) {
            Setting::set('company_address', implode(', ', $addressParts));
        }

        $email = trim((string) ($row['email_sales'] ?? ''));

        if ($email === '') {
            $email = trim((string) ($row['email_accounts'] ?? ''));
        }

        if ($email !== '') {
            Setting::set('company_email', $email);
        }

        Setting::flushCache();

        return MapOutcome::Updated;
    }

    private function applyCompanySetting(array $row): MapOutcome
    {
        $name = trim((string) ($row['name'] ?? ''));
        $value = trim((string) ($row['value'] ?? ''));

        if ($name === '' || $value === '') {
            return MapOutcome::Skipped;
        }

        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? '');
        $settingKey = self::KNOWN_SETTING_KEYS[$normalized] ?? null;

        // Unmapped legacy setting columns are intentionally not persisted as ad-hoc settings rows;
        // they surface as Skipped for manual review rather than growing the settings table freely.
        if ($settingKey === null) {
            return MapOutcome::Skipped;
        }

        $outcome = Setting::get($settingKey) === null ? MapOutcome::Added : MapOutcome::Updated;

        Setting::set($settingKey, $value);
        Setting::flushCache();

        return $outcome;
    }
}
