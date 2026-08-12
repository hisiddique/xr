<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
            background: #ffffff;
            line-height: 1.15;
            padding: 62mm 10mm 22mm 10mm;
        }

        /* Inner 2-col layout table for chrome rows */
        .split { width: 100%; border-collapse: collapse; }
        .split > tbody > tr > td {
            width: 50%;
            border: none;
            padding: 7px 9px;
            vertical-align: top;
        }
        .split > tbody > tr > td.left { border-right: 1px solid #111827; }

        /* Header band */
        .company-name { font-size: 19px; font-weight: bold; line-height: 1.05; }
        .company-tagline { font-size: 11px; font-weight: bold; margin-top: 2px; color: #1f2937; }
        .header-right { font-size: 10.5px; line-height: 1.15; }

        .kv { width: 100%; border-collapse: collapse; }
        .kv td { border: none; padding: 1px 0; font-size: 10.5px; line-height: 1.2; }
        .kv td.k { width: 60px; font-weight: bold; white-space: nowrap; }
        .kv td.c { width: 8px; }

        /* Customer + statement-meta band */
        .meta-left { font-size: 10.5px; line-height: 1.15; }
        .meta-right { font-size: 10.5px; line-height: 1.15; }
        .doc-title { text-align: center; font-weight: bold; font-size: 15px; margin-bottom: 4px; line-height: 1.1; }
        .bill-company { font-weight: bold; font-size: 12px; margin-bottom: 1px; line-height: 1.15; }
        .bill-name { font-size: 11px; color: #1f2937; margin-bottom: 1px; line-height: 1.15; }
        .bill-addr { font-size: 10.5px; color: #374151; line-height: 1.2; }

        /* Fixed page header — chrome (company + customer/meta bands) */
        .page-header {
            position: fixed;
            top: 10mm;
            left: 10mm;
            right: 10mm;
        }
        .chrome {
            width: 100%;
            border-collapse: collapse;
        }
        .chrome > tbody > tr > td {
            border: 1px solid #111827;
            padding: 0;
            vertical-align: top;
        }
        .chrome > tbody > tr:first-child > td { border-top: 1.25px solid #111827; }
        .chrome > tbody > tr:last-child > td { border-bottom: 1.25px solid #111827; }
        .chrome > tbody > tr > td:first-child { border-left: 1.25px solid #111827; }
        .chrome > tbody > tr > td:last-child { border-right: 1.25px solid #111827; }
        .chrome > tbody > tr.headline > td { border: none !important; }
        .chrome > tbody > tr.headline .split td.left { border-right: none !important; }

        /* Content box rails — fills the area from items down to the footer on every page. */
        .content-frame {
            position: fixed;
            left: 10mm;
            right: 10mm;
            top: 62mm;
            bottom: 22mm;
            border-left: 1.25px solid #111827;
            border-right: 1.25px solid #111827;
            border-bottom: 1.25px solid #111827;
        }

        /* Items table — standalone flowing table; thead repeats on every page */
        .items {
            width: 100%;
            border-collapse: collapse;
        }
        .items th {
            background: #f3f4f6;
            border: 1px solid #111827;
            padding: 5px 8px;
            font-size: 10.5px;
            text-align: left;
            font-weight: bold;
        }
        .items th.right { text-align: right; }
        .items td {
            border: 1px solid #111827;
            padding: 7px 9px;
            vertical-align: top;
        }
        .item-cell { padding: 6px 9px !important; line-height: 1.2; font-size: 10.5px; }
        .item-cell.right { text-align: right; }
        .item-cell.bold { font-weight: bold; }

        /* Aging summary block — flows after the items table, only appears once at the true end
           of the report (not repeated per page); margin-top keeps it visually separate. */
        .aging-box {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            page-break-inside: avoid;
        }
        .aging-box > tbody > tr > td {
            border: 1px solid #111827;
            padding: 0;
            vertical-align: top;
        }
        .aging-header {
            padding: 6px 9px !important;
            border-bottom: 1px solid #111827 !important;
        }
        .aging-header table { width: 100%; border-collapse: collapse; }
        .aging-header td { border: none !important; padding: 0 !important; font-size: 11.5px; font-weight: bold; }
        .aging-header td.right { text-align: right; }

        .aging-cols { width: 100%; border-collapse: collapse; }
        .aging-cols td {
            border: none !important;
            border-right: 1px solid #d1d5db !important;
            width: 20%;
            text-align: center;
            padding: 6px 4px !important;
            vertical-align: top;
        }
        .aging-cols td:last-child { border-right: none !important; }
        .aging-label { font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.02em; color: #374151; margin-bottom: 2px; }
        .aging-amount { font-size: 11px; }

        /* Fixed page footer — legal/company info only, no page number here */
        .page-footer {
            position: fixed;
            left: 10mm;
            right: 10mm;
            bottom: 8mm;
            font-size: 9.5px;
            color: #374151;
            line-height: 1.5;
        }
        .page-footer .director { font-weight: bold; margin-bottom: 2px; }
    </style>
</head>
@php
    $companyName     = \App\Models\Setting::get('company_name', config('app.name'));
    $companyTagline  = \App\Models\Setting::get('company_tagline', '');
    $companyAddress  = \App\Models\Setting::get('company_address', '');
    $companyEmail    = \App\Models\Setting::get('company_email', '');
    $companyEmailAcc = \App\Models\Setting::get('company_email_accounts', '');
    $companyTelSales = \App\Models\Setting::get('company_tel_sales', '');
    $companyTelAcc   = \App\Models\Setting::get('company_tel_accounts', '');
    $companyDirector   = \App\Models\Setting::get('company_director', '');
    $companyRegNo      = \App\Models\Setting::get('company_registration_no', '');
    $companyVatNo      = \App\Models\Setting::get('company_vat_no', '');
    $companyRegAddress = \App\Models\Setting::get('company_registered_address', '');

    $custPerson = $customer ? trim(
        ($customer->title?->name ?? '').' '.
        ($customer->first_name ?? '').' '.
        ($customer->last_name ?? '')
    ) : '';
    $custAddressLines = $customer ? collect([
        $customer->address_1,
        $customer->address_2,
        $customer->town,
        $customer->post_code,
    ])->filter()->values() : collect();

    $pageNumX = 365;
    $pageNumY = 128;
@endphp

<body>

{{-- Fixed page header: company chrome + customer/statement-meta bands --}}
<div class="page-header">
    <table class="chrome">
        <tbody>
            <tr class="headline">
                <td>
                    <table class="split"><tr>
                        <td class="left">
                            <div class="company-name">{{ $companyName }}</div>
                            @if($companyTagline)
                                <div class="company-tagline">{{ $companyTagline }}</div>
                            @endif
                        </td>
                        <td class="right header-right">
                            @if($companyAddress)
                                {!! nl2br(e($companyAddress)) !!}
                            @endif
                            <table class="kv" style="margin-top: 2px">
                                @if($companyTelSales)
                                    <tr><td class="k">Tel Sales</td><td class="c">:</td><td>{{ $companyTelSales }}</td></tr>
                                @endif
                                @if($companyTelAcc)
                                    <tr><td class="k">Accounts</td><td class="c">:</td><td>{{ $companyTelAcc }}</td></tr>
                                @endif
                                @if($companyEmail)
                                    <tr><td class="k">E-Mail</td><td class="c">:</td><td>{{ $companyEmail }}</td></tr>
                                @endif
                                @if($companyEmailAcc)
                                    <tr><td class="k">E-Mail</td><td class="c">:</td><td>{{ $companyEmailAcc }}</td></tr>
                                @endif
                            </table>
                        </td>
                    </tr></table>
                </td>
            </tr>
            <tr>
                <td>
                    <table class="split"><tr>
                        <td class="left meta-left">
                            @if($customer)
                                @if($customer->company_name)
                                    <div class="bill-company">{{ $customer->company_name }}</div>
                                @endif
                                @if($custPerson)
                                    <div class="bill-name">{{ $custPerson }}</div>
                                @endif
                                <div class="bill-addr">
                                    @foreach($custAddressLines as $line)
                                        {{ $line }}@if(! $loop->last)<br>@endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="right meta-right">
                            <div class="doc-title">Statement</div>
                            <table class="kv">
                                <tr><td class="k">Account</td><td class="c">:</td><td>{{ $customer->reference ?? '—' }}</td></tr>
                                <tr><td class="k">Date</td><td class="c">:</td><td>{{ now()->format('d.m.y') }}</td></tr>
                                <tr><td class="k">Sheet</td><td class="c">:</td><td>&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr></table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Content box rails — fill the area down to the footer on every page --}}
<div class="content-frame"></div>

{{-- Standalone items table; thead repeats on every page --}}
<table class="items">
    <thead>
        <tr>
            <th style="width:12%">Date</th>
            <th>Details</th>
            <th class="right" style="width:16%">Total Value</th>
            <th class="right" style="width:14%">Credit</th>
            <th class="right" style="width:16%">Amount Due</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoices as $invoice)
            <tr>
                <td class="item-cell">{{ $invoice['doc_date'] ? \Carbon\Carbon::parse($invoice['doc_date'])->format('d.m.y') : '' }}</td>
                <td class="item-cell">{{ trim($invoice['doc_number'].' '.($invoice['order_no'] ?? '')) }}</td>
                <td class="item-cell right">£{{ number_format($invoice['total_value'], 2) }}</td>
                <td class="item-cell right">{{ $invoice['credited'] > 0 ? '£'.number_format($invoice['credited'], 2) : '' }}</td>
                <td class="item-cell right bold">£{{ number_format($invoice['outstanding'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Aging summary block — normal document flow, so it only ever appears once,
     right after the last invoice row on the true last page. --}}
<table class="aging-box">
    <tbody>
        <tr>
            <td class="aging-header">
                <table>
                    <tr>
                        <td>Overdue Amount:</td>
                        <td class="right">Totals: £{{ number_format($aging['total'], 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <table class="aging-cols">
                    <tr>
                        @foreach($aging['labels'] as $label => $amount)
                            <td>
                                <div class="aging-label">{{ $label }}</div>
                                <div class="aging-amount">£{{ number_format($amount, 2) }}</div>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </tbody>
</table>

{{-- Per-page Sheet No. drawn via inline PHP script into the empty value cell
     of the Sheet row in the header meta band — matches the document.blade.php
     convention of putting the page indicator in the letterhead, not the footer. --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("DejaVu Sans", "normal");
    $pdf->page_text({{ $pageNumX }}, {{ $pageNumY }}, "{PAGE_NUM} of {PAGE_COUNT}", $font, 7.875);
}
</script>

{{-- Fixed page footer (paints on every page) --}}
<div class="page-footer">
    @if($companyDirector)
        <div class="director">Director: {{ $companyDirector }}</div>
    @endif
    @if($companyRegNo || $companyVatNo)
        <div>
            @if($companyRegNo)Registered in England: {{ $companyRegNo }}@endif
            @if($companyRegNo && $companyVatNo), @endif
            @if($companyVatNo)VAT No. {{ $companyVatNo }}@endif
        </div>
    @endif
    @if($companyRegAddress)
        <div>{{ $companyRegAddress }}</div>
    @endif
</div>

</body>
</html>
