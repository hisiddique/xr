<?php

namespace App\Services;

use App\Models\Setting;

class CompanyLetterheadService
{
    public const ADDRESS_RESERVED_LINES = 3;

    /**
     * Header (letterhead) fields shared by every PDF template: company name/tagline,
     * a fixed-height address block, and fixed-height contact rows. Both are padded to
     * a constant line count (rather than only rendered when present) so the header's
     * rendered height never varies for the common case — dompdf's page_text() overlays
     * (e.g. a "Sheet No." page number) are drawn at fixed coordinates and can't track
     * dynamic content height, so a stable header height is what keeps them aligned.
     *
     * @return array{
     *     name: string,
     *     tagline: string,
     *     addressLines: array<int, string>,
     *     addressOverflowLines: int,
     *     kvRows: array<int, array{0: string, 1: string}>,
     * }
     */
    public function header(): array
    {
        $address = (string) Setting::get('company_address', '');
        $addressLines = $address !== '' ? preg_split('/\r\n|\r|\n/', trim($address)) : [];
        $addressOverflowLines = max(0, count($addressLines) - self::ADDRESS_RESERVED_LINES);

        while (count($addressLines) < self::ADDRESS_RESERVED_LINES) {
            $addressLines[] = '';
        }

        return [
            'name' => (string) Setting::get('company_name', config('app.name')),
            'tagline' => (string) Setting::get('company_tagline', ''),
            'addressLines' => $addressLines,
            'addressOverflowLines' => $addressOverflowLines,
            'kvRows' => [
                ['Tel Sales', (string) Setting::get('company_tel_sales', '')],
                ['Accounts', (string) Setting::get('company_tel_accounts', '')],
                ['E-Mail', (string) Setting::get('company_email', '')],
                ['E-Mail', (string) Setting::get('company_email_accounts', '')],
            ],
        ];
    }

    /**
     * Legal footer fields shared by every PDF template.
     *
     * @return array{director: string, regNo: string, vatNo: string, regAddress: string}
     */
    public function footer(?string $director = null): array
    {
        return [
            'director' => $director ?? (string) Setting::get('company_director', ''),
            'regNo' => (string) Setting::get('company_registration_no', ''),
            'vatNo' => (string) Setting::get('company_vat_no', ''),
            'regAddress' => (string) Setting::get('company_registered_address', ''),
        ];
    }
}
