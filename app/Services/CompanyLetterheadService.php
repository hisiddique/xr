<?php

namespace App\Services;

use App\Models\Setting;

class CompanyLetterheadService
{
    public const ADDRESS_RESERVED_LINES = 3;

    /**
     * Conservative estimate of characters that fit on one line of the header's address
     * column before the browser/dompdf word-wraps it. A single long line with no explicit
     * break (e.g. an address typed as one run-on line) still visually wraps to multiple
     * lines — counting only "\n" characters misses that entirely, so a long single line
     * would be reserved as 1 line while actually rendering as 2+, breaking the fixed-height
     * assumption the page-number alignment depends on (see header() doc block below).
     */
    private const ADDRESS_CHARS_PER_LINE = 56;

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

        $visualLineCount = fn (string $line): int => $line === ''
            ? 1
            : max(1, (int) ceil(mb_strlen($line) / self::ADDRESS_CHARS_PER_LINE));

        $totalVisualLines = array_sum(array_map($visualLineCount, $addressLines));
        $addressOverflowLines = max(0, $totalVisualLines - self::ADDRESS_RESERVED_LINES);

        while ($totalVisualLines < self::ADDRESS_RESERVED_LINES) {
            $addressLines[] = '';
            $totalVisualLines++;
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
